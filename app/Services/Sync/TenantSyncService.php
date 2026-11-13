<?php

namespace App\Services\Sync;

use App\Exceptions\BitrixApiException;
use App\Models\SyncedEntityState;
use App\Models\SyncedSpaLink;
use App\Models\SyncLog;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Models\TenantEntityConfig;
use App\Models\TenantFieldMapping;
use App\Models\TenantSpaBucket;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixFieldAdapter;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TenantSyncService
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixFieldAdapter $adapter,
        private readonly SubscriptionGate $gate,
    ) {}

    public static function selfWriteCacheKey(int $tenantId, int $entityTypeId, int $entityId): string
    {
        return "b24_self_write:{$tenantId}:{$entityTypeId}:{$entityId}";
    }

    public static function spaSelfWriteCacheKey(int $tenantId, int $spaEntityTypeId, int $spaItemId): string
    {
        return "b24_self_write_spa:{$tenantId}:{$spaEntityTypeId}:{$spaItemId}";
    }

    /**
     * Load initial data needed by the embedded CRM entity widget.
     *
     * @return array<string, mixed>
     */
    public function loadWidgetContext(Tenant $tenant, int $entityTypeId, int $entityId): array
    {
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        $catalogConfig = $tenant->catalogConfig;
        $entityConfig = $tenant->entityConfigs()
            ->with('fieldMappings')
            ->where('entity_type_id', $entityTypeId)
            ->first();

        $entity = $this->fetchEntity($tenant, $entityTypeId, $entityId);
        $ownerType = $entityConfig?->owner_type_code ?: TenantEntityConfig::ownerCodeForEntityTypeId($entityTypeId);
        $productRows = $this->fetchProductRows($tenant, $ownerType, $entityId);

        $currencyId = (string) ($entity['CURRENCY_ID'] ?? $entity['currencyId'] ?? 'USD');
        $currencies = $tenant->schema_snapshot_json['currencies'] ?? [];
        if ($currencies === []) {
            try {
                $currRes = $this->client->call($tenant, 'crm.currency.list');
                foreach ((array) ($currRes['result'] ?? []) as $c) {
                    if (! empty($c['CURRENCY'])) {
                        $currencies[] = [
                            'code' => (string) $c['CURRENCY'],
                            'name' => (string) ($c['FULL_NAME'] ?? $c['CURRENCY']),
                            'base' => ($c['BASE'] ?? 'N') === 'Y',
                            'format' => (string) ($c['FORMAT_STRING'] ?? ''),
                        ];
                    }
                }
            } catch (BitrixApiException) {
                $currencies = [['code' => $currencyId, 'name' => $currencyId, 'base' => true, 'format' => '#']];
            }
        }

        // Batch fetch catalog product properties for existing rows
        $productIds = collect($productRows)
            ->pluck('productId')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $catalogProducts = $this->fetchCatalogProducts($tenant, $catalogConfig, $productIds);

        // Build enum choice lists from tenant_enum_mappings + catalog_config
        $enumRows = $tenant->enumMappings()->where('spa_entity_type_id', 0)->get();
        $costTypeChoices = $this->buildChoiceList($enumRows, 'cost_type', 'cost_type', $catalogConfig?->resolvedCostTypesConfig() ?? []);
        $optionChoices = $this->buildChoiceList($enumRows, 'option_group', 'option_group', $catalogConfig?->resolvedOptionsConfig() ?? []);
        $paymentChoices = $this->buildPaymentChoiceList($enumRows);

        // Extract current option metadata values from CRM entity fields
        $metaValues = [];
        if ($entityConfig) {
            foreach ($entityConfig->fieldMappings->where('role', TenantFieldMapping::ROLE_OPTION_META) as $mapping) {
                $metaValues[$mapping->role_qualifier] = (string) $this->adapter->readFieldValue($entity, $mapping->b24_field_code, '');
            }
        }

        $extraAttributes = (array) ($catalogConfig?->extra_attributes_json ?? []);
        $rows = [];
        foreach ($productRows as $idx => $r) {
            $pid = (int) ($r['productId'] ?? 0);
            $catProd = $pid > 0 ? ($catalogProducts[$pid] ?? null) : null;

            $costVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig?->cost_type_prop_code);
            $optVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig?->option_prop_code);
            $payVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig?->payments_prop_code);

            $costKey = $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'cost_type', 'cost_type', $costVal);
            $optKey = $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'option_group', 'option_group', $optVal);
            $payKey = $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'payment_schedule', 'payment_schedule', $payVal);

            $extraValues = [];
            foreach ($extraAttributes as $attr) {
                $code = $attr['prop_code'] ?? null;
                if ($code) {
                    $extraValues[$attr['key']] = (string) ($this->adapter->extractCatalogPropertyValue($catProd, $code) ?? '');
                }
            }

            $rows[] = [
                'rowId' => (int) ($r['id'] ?? 0),
                'productId' => $pid ?: null,
                'productName' => (string) ($r['productName'] ?? $catProd['name'] ?? ''),
                'price' => (float) ($r['price'] ?? 0),
                'quantity' => (float) ($r['quantity'] ?? 1),
                'taxRate' => (float) ($r['taxRate'] ?? 0),
                'taxIncluded' => ($r['taxIncluded'] ?? 'N') === 'Y',
                'sort' => (int) ($r['sort'] ?? (($idx + 1) * 10)),
                'costTypeKey' => $costKey ?: ($costTypeChoices[0]['logical_key'] ?? 'professional'),
                'optionKey' => $optKey ?: ($optionChoices[0]['logical_key'] ?? 'option_1'),
                'paymentKey' => $payKey ?: '',
                'extraAttributes' => $extraValues,
            ];
        }

        $lastState = SyncedEntityState::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type_id', $entityTypeId)
            ->where('entity_id', $entityId)
            ->first();

        return [
            'entity' => [
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
                'owner_type_code' => $ownerType,
                'title' => (string) ($entity['TITLE'] ?? $entity['title'] ?? "#{$entityId}"),
                'currency_id' => $currencyId,
                'opportunity' => (float) ($entity['OPPORTUNITY'] ?? $entity['opportunity'] ?? 0),
                'is_configured' => $entityConfig !== null && $catalogConfig !== null,
            ],
            'currencies' => $currencies,
            'schema' => [
                'fee_sync_mode' => $tenant->fee_sync_mode,
                'options' => $optionChoices,
                'cost_types' => $costTypeChoices,
                'payments' => $paymentChoices,
                'option_meta_schema' => $catalogConfig?->resolvedOptionMetaSchema() ?? TenantCatalogConfig::defaultOptionMetaSchema(),
                'extra_attributes' => $extraAttributes,
                'iblock_id' => $catalogConfig?->iblock_id,
            ],
            'rows' => $rows,
            'option_meta' => $metaValues,
            'last_synced_at' => $lastState?->last_synced_at?->toIso8601String(),
            'subscription' => [
                'allowed' => $subEval['allowed'],
                'reason' => $subEval['reason'],
                'banner' => $subEval['banner'],
                'status' => $subEval['status'],
                'plan_name' => $subEval['plan']?->name,
                'sync_count' => $subEval['usage']->sync_count,
                'max_monthly_syncs' => $subEval['max_monthly_syncs'],
                'max_options' => $subEval['max_options'],
            ],
        ];
    }

    /**
     * Execute full entity synchronization (widget save OR background webhook sync).
     *
     * @param  ?array<string, mixed>  $widgetPayload  Present when triggered via Widget Save/Clear
     * @return array{success: bool, skipped?: bool, actions: list<array<string, mixed>>, errors: list<string>, summary?: array<string, mixed>}
     */
    public function syncEntity(
        Tenant $tenant,
        int $entityTypeId,
        int $entityId,
        string $triggerSource = 'webhook',
        ?array $widgetPayload = null,
        bool $force = false,
    ): array {
        $startTime = microtime(true);
        $this->client->resetMetrics();
        $actions = [];
        $errors = [];

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: true);
        if (! $subEval['allowed']) {
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'blocked', $startTime, [], [$subEval['reason'] ?? 'Blocked by subscription gate.']);

            return [
                'success' => false,
                'actions' => [],
                'errors' => [$subEval['reason'] ?? 'Subscription or quota blocked sync.'],
            ];
        }

        $catalogConfig = $tenant->catalogConfig;
        if (! $catalogConfig) {
            $err = 'Catalog schema is not configured for this portal. Complete setup in App Settings.';
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'failed', $startTime, [], [$err]);

            return ['success' => false, 'actions' => [], 'errors' => [$err]];
        }

        $entityConfig = $tenant->entityConfigs()
            ->with('fieldMappings')
            ->where('entity_type_id', $entityTypeId)
            ->first();

        if (! $entityConfig || ! $entityConfig->is_enabled) {
            $err = "Entity type {$entityTypeId} is not configured or enabled for fee synchronization.";
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'skipped', $startTime, [], [$err]);

            return ['success' => false, 'skipped' => true, 'actions' => [], 'errors' => [$err]];
        }

        $reservedUsageId = $this->gate->tryReserveSyncSlot($tenant, $subEval['max_monthly_syncs']);
        if ($reservedUsageId === null) {
            $err = 'Monthly sync quota reached. Upgrade your plan to continue syncing.';
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'blocked', $startTime, [], [$err]);

            return ['success' => false, 'actions' => [], 'errors' => [$err]];
        }

        $syncCommitted = false;

        try {
            return $this->executeSyncPipeline(
                $tenant,
                $catalogConfig,
                $entityConfig,
                $subEval,
                $entityTypeId,
                $entityId,
                $triggerSource,
                $widgetPayload,
                $force,
                $startTime,
                $actions,
                $errors,
                $syncCommitted
            );
        } finally {
            if (! $syncCommitted) {
                $this->gate->releaseReservedSyncSlot($reservedUsageId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $subEval
     * @param  ?array<string, mixed>  $widgetPayload
     * @param  list<array<string, mixed>>  $actions
     * @param  list<string>  $errors
     * @return array{success: bool, skipped?: bool, actions: list<array<string, mixed>>, errors: list<string>, summary?: array<string, mixed>}
     */
    private function executeSyncPipeline(
        Tenant $tenant,
        TenantCatalogConfig $catalogConfig,
        TenantEntityConfig $entityConfig,
        array $subEval,
        int $entityTypeId,
        int $entityId,
        string $triggerSource,
        ?array $widgetPayload,
        bool $force,
        float $startTime,
        array &$actions,
        array &$errors,
        bool &$syncCommitted,
    ): array {
        $ownerType = $entityConfig->owner_type_code;

        // 1. If triggered by Widget Save, apply product rows & catalog attribute updates atomically first
        $currencyOverride = null;
        $optionMetaOverrides = [];

        if ($widgetPayload !== null) {
            Cache::put(self::selfWriteCacheKey($tenant->id, $entityTypeId, $entityId), true, 15);

            $currencyOverride = ! empty($widgetPayload['currencyId'])
                ? strtoupper(trim((string) $widgetPayload['currencyId']))
                : null;
            $optionMetaOverrides = (array) ($widgetPayload['optionMeta'] ?? []);

            $inputRows = (array) ($widgetPayload['rows'] ?? []);
            $productRowsPayload = [];
            foreach ($inputRows as $idx => $row) {
                $rowSpec = [
                    'productName' => trim((string) ($row['productName'] ?? '')),
                    'price' => round((float) ($row['price'] ?? 0), 4),
                    'quantity' => max(0.0001, (float) ($row['quantity'] ?? 1)),
                    'taxRate' => round((float) ($row['taxRate'] ?? 0), 2),
                    'taxIncluded' => ! empty($row['taxIncluded']) ? 'Y' : 'N',
                    'sort' => (int) ($row['sort'] ?? (($idx + 1) * 10)),
                ];
                if (! empty($row['rowId']) && (int) $row['rowId'] > 0) {
                    $rowSpec['id'] = (int) $row['rowId'];
                }
                if (! empty($row['productId']) && (int) $row['productId'] > 0) {
                    $rowSpec['productId'] = (int) $row['productId'];
                }
                if ($rowSpec['productName'] !== '' || ! empty($rowSpec['productId'])) {
                    $productRowsPayload[] = $rowSpec;
                }
            }

            $this->saveProductRows($tenant, $ownerType, $entityId, $productRowsPayload, $actions, $errors);

            // Sync modified catalog product attributes back to the Commercial Catalog (preserving valueId)
            $this->syncCatalogPropertiesFromWidget(
                $tenant,
                $catalogConfig,
                $inputRows,
                $actions,
                $errors,
                (int) ($subEval['max_options'] ?? 2),
                (bool) ($subEval['features']['allow_custom_product_attributes'] ?? false)
            );
        }

        // 2. Fetch authoritative entity + product rows + catalog products
        $entity = $this->fetchEntity($tenant, $entityTypeId, $entityId);
        if (! $entity) {
            $err = "CRM entity ({$entityTypeId} #{$entityId}) not found.";
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'failed', $startTime, $actions, [$err]);

            return ['success' => false, 'actions' => $actions, 'errors' => [$err]];
        }

        $currencyId = $currencyOverride ?: (string) ($entity['CURRENCY_ID'] ?? $entity['currencyId'] ?? 'USD');
        $productRows = $this->fetchProductRows($tenant, $ownerType, $entityId);

        $productIds = collect($productRows)
            ->pluck('productId')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $catalogProducts = $this->fetchCatalogProducts($tenant, $catalogConfig, $productIds);

        // Map widget row overrides by (productId / index) if catalog row wasn't created or wasn't returned yet
        $widgetRowByProductId = [];
        if ($widgetPayload !== null) {
            foreach ((array) ($widgetPayload['rows'] ?? []) as $wr) {
                if (! empty($wr['productId'])) {
                    $widgetRowByProductId[(int) $wr['productId']] = $wr;
                }
            }
        }

        // 3. Bucket calculations (Subtotals, Option Totals, VAT/Opportunity)
        $maxOptions = max(1, (int) ($subEval['max_options'] ?? 2));
        $optionsConfig = array_slice(
            array_values(array_filter(
                $catalogConfig->resolvedOptionsConfig(),
                fn (array $o) => ($o['is_enabled'] ?? true) === true
            )),
            0,
            $maxOptions
        );
        $costTypesConfig = array_values(array_filter(
            $catalogConfig->resolvedCostTypesConfig(),
            fn (array $c) => ($c['is_enabled'] ?? true) === true
        ));
        $allowedOptionKeys = array_map(fn (array $o) => (string) ($o['key'] ?? ''), $optionsConfig);
        $allowedCostTypeKeys = array_map(fn (array $c) => (string) ($c['key'] ?? ''), $costTypesConfig);
        $activeSpaBuckets = $tenant->spaBuckets()->where('is_active', true)->get();

        $bucketSubtotals = [];
        foreach ($optionsConfig as $opt) {
            foreach ($costTypesConfig as $ct) {
                $bucketSubtotals[$this->bucketKey($ct['key'], $opt['key'])] = 0.0;
            }
        }
        $optionTotals = [];
        foreach ($optionsConfig as $opt) {
            $optionTotals[$opt['key']] = 0.0;
        }

        $totalWithoutTax = 0.0;
        $totalWithTax = 0.0;
        $preparedSpaRows = [];
        $hashRows = [];

        foreach ($productRows as $rowIdx => $row) {
            $rowId = (int) ($row['id'] ?? 0);
            $productId = (int) ($row['productId'] ?? 0);
            $productName = trim((string) ($row['productName'] ?? ''));
            if ($productName === '' && $productId <= 0) {
                continue;
            }

            $catProd = $productId > 0 ? ($catalogProducts[$productId] ?? null) : null;
            if ($productName === '' && $catProd) {
                $productName = (string) ($catProd['name'] ?? "Product #{$productId}");
            }

            $widgetOverride = $widgetRowByProductId[$productId]
                ?? ($widgetPayload['rows'][$rowIdx] ?? null);

            $costTypeVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig->cost_type_prop_code);
            $optionVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig->option_prop_code);
            $paymentsVal = $this->adapter->extractCatalogPropertyValue($catProd, $catalogConfig->payments_prop_code);

            $rawWidgetCostType = isset($widgetOverride['costTypeKey']) && in_array((string) $widgetOverride['costTypeKey'], $allowedCostTypeKeys, true)
                ? (string) $widgetOverride['costTypeKey']
                : null;
            $rawWidgetOption = isset($widgetOverride['optionKey']) && in_array((string) $widgetOverride['optionKey'], $allowedOptionKeys, true)
                ? (string) $widgetOverride['optionKey']
                : null;

            $costTypeKey = $rawWidgetCostType
                ?: $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'cost_type', 'cost_type', $costTypeVal);
            if ($costTypeKey !== null && ! in_array($costTypeKey, $allowedCostTypeKeys, true)) {
                $costTypeKey = null;
            }

            $optionKey = $rawWidgetOption
                ?: $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'option_group', 'option_group', $optionVal);
            if ($optionKey !== null && ! in_array($optionKey, $allowedOptionKeys, true)) {
                $optionKey = null;
            }

            $paymentKey = ($widgetOverride['paymentKey'] ?? null)
                ?: $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'payment_schedule', 'payment_schedule', $paymentsVal);

            $price = (float) ($row['price'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 1);
            $taxRate = (float) ($row['taxRate'] ?? 0);
            $taxIncluded = ($row['taxIncluded'] ?? 'N') === 'Y';

            $rowGrossBase = $price * $qty;
            if ($taxIncluded && $taxRate > 0) {
                $rowNet = $rowGrossBase / (1 + ($taxRate / 100));
                $rowTotal = $rowGrossBase;
            } else {
                $rowNet = $rowGrossBase;
                $rowTotal = $rowGrossBase * (1 + ($taxRate / 100));
            }

            $totalWithoutTax += $rowNet;
            $totalWithTax += $rowTotal;

            $bKey = ($costTypeKey && $optionKey) ? $this->bucketKey($costTypeKey, $optionKey) : null;
            if ($bKey !== null) {
                $bucketSubtotals[$bKey] = ($bucketSubtotals[$bKey] ?? 0.0) + $rowGrossBase;
            }
            if ($optionKey !== null) {
                $optionTotals[$optionKey] = ($optionTotals[$optionKey] ?? 0.0) + $rowGrossBase;
            }

            $hashRows[] = [
                'pid' => $productId,
                'name' => $productName,
                'price' => round($price, 4),
                'qty' => round($qty, 4),
                'taxRate' => round($taxRate, 2),
                'taxIncluded' => $taxIncluded,
                'costTypeKey' => $costTypeKey,
                'optionKey' => $optionKey,
                'paymentKey' => $paymentKey,
            ];

            // Resolve matching SPA bucket if mode uses SPAs
            if ($tenant->fee_sync_mode !== Tenant::MODE_SUBTOTALS_ONLY && $activeSpaBuckets->isNotEmpty()) {
                $targetBucket = $this->matchSpaBucket($activeSpaBuckets, $costTypeKey, $optionKey, $bKey);
                if ($targetBucket && $targetBucket->spa_entity_type_id) {
                    $externalId = $productId > 0
                        ? "{$entityTypeId}_{$entityId}_p{$productId}"
                        : "{$entityTypeId}_{$entityId}_r{$rowId}";

                    $preparedSpaRows[] = [
                        'row_id' => $rowId,
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'bucket' => $targetBucket,
                        'external_id' => $externalId,
                        'cost_type_key' => $costTypeKey,
                        'option_key' => $optionKey,
                        'payment_key' => $paymentKey,
                        'cost_type_enum_id' => $costTypeVal,
                        'option_enum_id' => $optionVal,
                        'payment_enum_id' => $paymentsVal,
                        'catalog_product' => $catProd,
                        'widget_extra' => (array) ($widgetOverride['extraAttributes'] ?? []),
                        'amount' => round($rowGrossBase, 2),
                        'quantity' => $qty,
                    ];
                }
            }
        }

        // Compute deterministic state hash to skip redundant webhook echoes
        $statePayload = [
            'currency' => $currencyId,
            'rows' => $hashRows,
            'subtotals' => $bucketSubtotals,
            'option_totals' => $optionTotals,
            'option_meta' => $optionMetaOverrides,
            'opportunity' => round($totalWithTax, 2),
            'mode' => $tenant->fee_sync_mode,
        ];
        $currentHash = hash('sha256', json_encode($statePayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        $existingState = SyncedEntityState::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type_id', $entityTypeId)
            ->where('entity_id', $entityId)
            ->first();

        if (! $force && $widgetPayload === null && $existingState && hash_equals($existingState->last_hash, $currentHash)) {
            $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, 'skipped', $startTime, [
                ['action' => 'skipped_identical_hash'],
            ], []);

            return [
                'success' => true,
                'skipped' => true,
                'actions' => [['action' => 'skipped_identical_hash']],
                'errors' => [],
            ];
        }

        // 4. Sync Child Fee SPA Items (Batched Create / Update / Type-Move / Orphan Cleanup)
        $linkedIdsByBucket = [];
        foreach ($activeSpaBuckets as $b) {
            $linkedIdsByBucket[$b->bucket_key] = [];
        }

        if ($tenant->fee_sync_mode !== Tenant::MODE_SUBTOTALS_ONLY && $activeSpaBuckets->isNotEmpty()) {
            $linkedIdsByBucket = $this->syncSpaItemsBatched(
                $tenant,
                $catalogConfig,
                $entityConfig,
                $entity,
                $entityTypeId,
                $entityId,
                $currencyId,
                $activeSpaBuckets,
                $preparedSpaRows,
                $actions,
                $errors
            );
        }

        // 5. Single Atomic Entity Field Update (Opportunity + Currency + Subtotals + Option Totals + Meta + Links + Status)
        $this->updateEntityMergedFields(
            $tenant,
            $entityConfig,
            $currencyId,
            $totalWithTax,
            $bucketSubtotals,
            $optionTotals,
            $optionMetaOverrides,
            $linkedIdsByBucket,
            $entityId,
            $actions,
            $errors
        );

        // 6. Persist SyncedEntityState & Usage counters
        SyncedEntityState::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
            ],
            [
                'last_hash' => $currentHash,
                'last_synced_at' => now(),
                'summary_json' => [
                    'currency' => $currencyId,
                    'subtotals' => $bucketSubtotals,
                    'option_totals' => $optionTotals,
                    'total_without_tax' => round($totalWithoutTax, 2),
                    'total_with_tax' => round($totalWithTax, 2),
                    'row_count' => count($hashRows),
                ],
            ]
        );

        $syncCommitted = true;
        $metrics = $this->client->getMetrics();
        $tenant->forceFill(['last_sync_at' => now()])->saveQuietly();
        $this->gate->incrementUsage($tenant, 0, $metrics['calls'], $triggerSource === 'webhook' ? 1 : 0);

        $status = $errors === [] ? 'success' : 'failed';
        $this->writeSyncLog($tenant, $entityTypeId, $entityId, $triggerSource, $status, $startTime, $actions, $errors);

        return [
            'success' => $errors === [],
            'actions' => $actions,
            'errors' => $errors,
            'summary' => [
                'currency' => $currencyId,
                'subtotals' => $bucketSubtotals,
                'option_totals' => $optionTotals,
                'total_without_tax' => round($totalWithoutTax, 2),
                'total_with_tax' => round($totalWithTax, 2),
            ],
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchCatalogProducts(Tenant $tenant, ?TenantCatalogConfig $catalogConfig, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $iblockId = $catalogConfig?->iblock_id;
        if ($iblockId) {
            try {
                $res = $this->client->call($tenant, 'catalog.product.list', [
                    'select' => ['id', 'iblockId', 'name', 'property*'],
                    'filter' => [
                        'iblockId' => $iblockId,
                        'id' => $productIds,
                    ],
                ]);

                $byId = [];
                foreach ((array) ($res['result']['products'] ?? []) as $p) {
                    if (! empty($p['id'])) {
                        $byId[(int) $p['id']] = $p;
                    }
                }
                if ($byId !== []) {
                    return $byId;
                }
            } catch (BitrixApiException) {
                // Fallback to batched catalog.product.get below.
            }
        }

        $commands = [];
        foreach ($productIds as $pid) {
            $commands["p_{$pid}"] = [
                'method' => 'catalog.product.get',
                'params' => ['id' => $pid],
            ];
        }

        $batchRes = $this->client->batch($tenant, $commands);
        $byId = [];
        foreach ($productIds as $pid) {
            $item = $batchRes["p_{$pid}"]['result']['product'] ?? $batchRes["p_{$pid}"]['result'] ?? null;
            if (is_array($item)) {
                $byId[$pid] = $item;
            }
        }

        return $byId;
    }

    private function fetchEntity(Tenant $tenant, int $entityTypeId, int $entityId): ?array
    {
        try {
            if ($entityTypeId === 1) {
                return $this->client->call($tenant, 'crm.lead.get', ['id' => $entityId])['result'] ?? null;
            }
            if ($entityTypeId === 2) {
                return $this->client->call($tenant, 'crm.deal.get', ['id' => $entityId])['result'] ?? null;
            }

            return $this->client->call($tenant, 'crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $entityId,
                'useOriginalUfNames' => 'Y',
            ])['result']['item'] ?? null;
        } catch (BitrixApiException) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchProductRows(Tenant $tenant, string $ownerType, int $entityId): array
    {
        $res = $this->client->call($tenant, 'crm.item.productrow.list', [
            'filter' => [
                '=ownerType' => $ownerType,
                '=ownerId' => $entityId,
            ],
        ]);

        return (array) ($res['result']['productRows'] ?? []);
    }

    private function saveProductRows(Tenant $tenant, string $ownerType, int $entityId, array $rows, array &$actions, array &$errors): void
    {
        try {
            $this->client->call($tenant, 'crm.item.productrow.set', [
                'ownerType' => $ownerType,
                'ownerId' => $entityId,
                'productRows' => $rows,
            ]);
            $actions[] = ['action' => 'saved_product_rows', 'count' => count($rows)];
        } catch (BitrixApiException $e) {
            if ($rows === []) {
                // If portal rejects empty productRows set, explicitly delete existing rows one by one cleanly.
                $existing = $this->fetchProductRows($tenant, $ownerType, $entityId);
                $delCmds = [];
                foreach ($existing as $er) {
                    if (! empty($er['id'])) {
                        $delCmds["del_{$er['id']}"] = [
                            'method' => 'crm.item.productrow.delete',
                            'params' => ['id' => (int) $er['id']],
                        ];
                    }
                }
                if ($delCmds !== []) {
                    $this->client->batch($tenant, $delCmds);
                }
                $actions[] = ['action' => 'cleared_product_rows', 'count' => count($delCmds)];

                return;
            }
            $errors[] = 'Failed saving CRM product rows: '.$e->getMessage();
        }
    }

    private function syncCatalogPropertiesFromWidget(
        Tenant $tenant,
        TenantCatalogConfig $catalogConfig,
        array $rows,
        array &$actions,
        array &$errors,
        int $maxOptions = 2,
        bool $allowExtraAttributes = true,
    ): void {
        $rowsWithProducts = collect($rows)->filter(fn (array $r) => ! empty($r['productId']) && (int) $r['productId'] > 0)->values()->all();
        if ($rowsWithProducts === []) {
            return;
        }

        $allowedOptionKeys = array_slice(
            collect($catalogConfig->resolvedOptionsConfig())
                ->filter(fn (array $o) => ($o['is_enabled'] ?? true) === true)
                ->pluck('key')
                ->all(),
            0,
            max(1, $maxOptions)
        );
        $allowedCostTypeKeys = collect($catalogConfig->resolvedCostTypesConfig())
            ->filter(fn (array $c) => ($c['is_enabled'] ?? true) === true)
            ->pluck('key')
            ->all();

        $productIds = array_values(array_unique(array_map(fn ($r) => (int) $r['productId'], $rowsWithProducts)));
        $currentProducts = $this->fetchCatalogProducts($tenant, $catalogConfig, $productIds);

        $updateCommands = [];
        foreach ($rowsWithProducts as $row) {
            $pid = (int) $row['productId'];
            $prod = $currentProducts[$pid] ?? null;
            $fields = [];

            if ($catalogConfig->cost_type_prop_code && ! empty($row['costTypeKey']) && in_array((string) $row['costTypeKey'], $allowedCostTypeKeys, true)) {
                $enumId = $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'cost_type', 'cost_type', (string) $row['costTypeKey']);
                if ($enumId !== null && (string) $this->adapter->extractCatalogPropertyValue($prod, $catalogConfig->cost_type_prop_code) !== (string) $enumId) {
                    $vid = $this->adapter->extractCatalogPropertyValueId($prod, $catalogConfig->cost_type_prop_code);
                    $fields[$catalogConfig->cost_type_prop_code] = $vid ? ['value' => $enumId, 'valueId' => $vid] : ['value' => $enumId];
                }
            }

            if ($catalogConfig->option_prop_code && ! empty($row['optionKey']) && in_array((string) $row['optionKey'], $allowedOptionKeys, true)) {
                $enumId = $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'option_group', 'option_group', (string) $row['optionKey']);
                if ($enumId !== null && (string) $this->adapter->extractCatalogPropertyValue($prod, $catalogConfig->option_prop_code) !== (string) $enumId) {
                    $vid = $this->adapter->extractCatalogPropertyValueId($prod, $catalogConfig->option_prop_code);
                    $fields[$catalogConfig->option_prop_code] = $vid ? ['value' => $enumId, 'valueId' => $vid] : ['value' => $enumId];
                }
            }

            if ($catalogConfig->payments_prop_code && ! empty($row['paymentKey'])) {
                $enumId = $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'payment_schedule', 'payment_schedule', (string) $row['paymentKey']);
                if ($enumId !== null && (string) $this->adapter->extractCatalogPropertyValue($prod, $catalogConfig->payments_prop_code) !== (string) $enumId) {
                    $vid = $this->adapter->extractCatalogPropertyValueId($prod, $catalogConfig->payments_prop_code);
                    $fields[$catalogConfig->payments_prop_code] = $vid ? ['value' => $enumId, 'valueId' => $vid] : ['value' => $enumId];
                }
            }

            if ($allowExtraAttributes) {
                foreach ((array) ($catalogConfig->extra_attributes_json ?? []) as $attr) {
                    $attrKey = $attr['key'] ?? null;
                    $propCode = $attr['prop_code'] ?? null;
                    if (! $attrKey || ! $propCode || ! array_key_exists($attrKey, (array) ($row['extraAttributes'] ?? []))) {
                        continue;
                    }
                    $newVal = (string) $row['extraAttributes'][$attrKey];
                    $oldVal = (string) ($this->adapter->extractCatalogPropertyValue($prod, $propCode) ?? '');
                    if ($newVal !== $oldVal) {
                        $vid = $this->adapter->extractCatalogPropertyValueId($prod, $propCode);
                        $fields[$propCode] = $vid ? ['value' => $newVal, 'valueId' => $vid] : ['value' => $newVal];
                    }
                }
            }

            if ($fields !== []) {
                $updateCommands["cat_{$pid}"] = [
                    'method' => 'catalog.product.update',
                    'params' => ['id' => $pid, 'fields' => $fields],
                ];
            }
        }

        if ($updateCommands !== []) {
            try {
                $this->client->batch($tenant, $updateCommands);
                $actions[] = ['action' => 'updated_catalog_products', 'count' => count($updateCommands)];
            } catch (BitrixApiException $e) {
                $errors[] = 'Catalog property update error: '.$e->getMessage();
            }
        }
    }

    /**
     * Create, update, move across buckets, and clean up orphaned SPA items using batched requests + DB link index.
     *
     * @return array<string, list<int>>
     */
    private function syncSpaItemsBatched(
        Tenant $tenant,
        TenantCatalogConfig $catalogConfig,
        TenantEntityConfig $entityConfig,
        array $entity,
        int $entityTypeId,
        int $entityId,
        string $currencyId,
        $activeSpaBuckets,
        array $preparedSpaRows,
        array &$actions,
        array &$errors,
    ): array {
        // 1. Gather currently known links from DB and entity UF link fields
        $existingLinks = SyncedSpaLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('parent_entity_type_id', $entityTypeId)
            ->where('parent_entity_id', $entityId)
            ->get()
            ->keyBy('external_id');

        $currentIdsByBucket = [];
        foreach ($activeSpaBuckets as $bucket) {
            $linkMapping = $entityConfig->fieldMappings
                ->where('role', TenantFieldMapping::ROLE_SPA_LINK)
                ->firstWhere('role_qualifier', $bucket->bucket_key);

            $rawIds = $linkMapping
                ? $this->adapter->readFieldValue($entity, $linkMapping->b24_field_code)
                : [];

            $currentIdsByBucket[$bucket->bucket_key] = $this->adapter->normalizeLinkedIds($rawIds);
        }

        $linkedIdsByBucket = [];
        foreach ($activeSpaBuckets as $bucket) {
            $linkedIdsByBucket[$bucket->bucket_key] = [];
        }

        $enumMappings = $tenant->enumMappings()->get();

        // High-efficiency batched pre-lookup for unresolved external IDs (eliminates O(R x B) loops)
        $unresolvedExternalIds = [];
        foreach ($preparedSpaRows as $prep) {
            $extId = (string) $prep['external_id'];
            if (! $existingLinks->has($extId)) {
                $unresolvedExternalIds[$extId] = true;
            }
        }

        $portalFoundMap = [];
        if ($unresolvedExternalIds !== []) {
            $portalFoundMap = $this->batchLookupSpaItemsByExternalIds(
                $tenant,
                $activeSpaBuckets,
                array_keys($unresolvedExternalIds)
            );
        }

        $commands = [];
        $commandMeta = [];
        $activeExternalIds = [];

        foreach ($preparedSpaRows as $idx => $prep) {
            /** @var TenantSpaBucket $bucket */
            $bucket = $prep['bucket'];
            $targetSpaTypeId = (int) $bucket->spa_entity_type_id;
            $externalId = (string) $prep['external_id'];
            $activeExternalIds[] = $externalId;

            $spaFields = $this->buildSpaItemPayload($tenant, $catalogConfig, $bucket, $prep, $currencyId, $enumMappings);
            $payloadHash = hash('sha256', json_encode($spaFields, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            // Check O(1) DB link record first, then batch lookup map, then fallback to xmlId search on portal
            $dbLink = $existingLinks->get($externalId);
            $existingSpaTypeId = $dbLink ? (int) $dbLink->spa_entity_type_id : null;
            $existingItemId = $dbLink ? (int) $dbLink->spa_item_id : null;

            if (! $existingItemId) {
                $found = $portalFoundMap[$externalId]
                    ?? $this->findSpaItemByXmlIdAcrossBuckets($tenant, $activeSpaBuckets, $externalId);
                if ($found) {
                    $existingSpaTypeId = $found['spa_entity_type_id'];
                    $existingItemId = $found['spa_item_id'];
                }
            }

            if ($existingItemId && $existingSpaTypeId === $targetSpaTypeId) {
                // Delta sync: If item payload is identical to last recorded hash, skip redundant API update
                if ($dbLink && $dbLink->payload_hash && hash_equals($dbLink->payload_hash, $payloadHash)) {
                    $linkedIdsByBucket[$bucket->bucket_key][] = $existingItemId;

                    continue;
                }

                Cache::put(self::spaSelfWriteCacheKey($tenant->id, $targetSpaTypeId, $existingItemId), true, 15);
                $cmdKey = "upd_{$idx}";
                $commands[$cmdKey] = [
                    'method' => 'crm.item.update',
                    'params' => [
                        'entityTypeId' => $targetSpaTypeId,
                        'id' => $existingItemId,
                        'fields' => $spaFields,
                        'useOriginalUfNames' => 'Y',
                    ],
                ];
                $commandMeta[$cmdKey] = [
                    'op' => 'update',
                    'prep' => $prep,
                    'existing_id' => $existingItemId,
                    'payload_hash' => $payloadHash,
                ];
                $linkedIdsByBucket[$bucket->bucket_key][] = $existingItemId;
            } else {
                if ($existingItemId && $existingSpaTypeId) {
                    Cache::put(self::spaSelfWriteCacheKey($tenant->id, $existingSpaTypeId, $existingItemId), true, 15);
                    $commands["del_old_{$idx}"] = [
                        'method' => 'crm.item.delete',
                        'params' => ['entityTypeId' => $existingSpaTypeId, 'id' => $existingItemId],
                    ];
                    SyncedSpaLink::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('spa_entity_type_id', $existingSpaTypeId)
                        ->where('spa_item_id', $existingItemId)
                        ->delete();
                    $actions[] = ['action' => 'deleted_moved_spa_item', 'spaType' => $existingSpaTypeId, 'spaId' => $existingItemId];
                }

                $cmdKey = "add_{$idx}";
                $commands[$cmdKey] = [
                    'method' => 'crm.item.add',
                    'params' => [
                        'entityTypeId' => $targetSpaTypeId,
                        'fields' => $spaFields,
                        'useOriginalUfNames' => 'Y',
                    ],
                ];
                $commandMeta[$cmdKey] = [
                    'op' => 'add',
                    'prep' => $prep,
                    'payload_hash' => $payloadHash,
                ];
            }
        }

        // Execute batched SPA updates/creates
        if ($commands !== []) {
            $batchResults = $this->client->batch($tenant, $commands);
            foreach ($commandMeta as $cmdKey => $meta) {
                $res = $batchResults[$cmdKey] ?? [];
                if (! empty($res['error'])) {
                    $errors[] = "SPA {$meta['op']} error for '{$meta['prep']['product_name']}': ".json_encode($res['error']);

                    continue;
                }

                /** @var TenantSpaBucket $bucket */
                $bucket = $meta['prep']['bucket'];
                $spaTypeId = (int) $bucket->spa_entity_type_id;
                $itemId = $meta['op'] === 'update'
                    ? (int) $meta['existing_id']
                    : (int) ($res['result']['item']['id'] ?? $res['result']['id'] ?? 0);

                if ($itemId > 0) {
                    Cache::put(self::spaSelfWriteCacheKey($tenant->id, $spaTypeId, $itemId), true, 15);
                    if ($meta['op'] === 'add') {
                        $linkedIdsByBucket[$bucket->bucket_key][] = $itemId;
                    }

                    SyncedSpaLink::updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'spa_entity_type_id' => $spaTypeId,
                            'spa_item_id' => $itemId,
                        ],
                        [
                            'parent_entity_type_id' => $entityTypeId,
                            'parent_entity_id' => $entityId,
                            'product_row_id' => $meta['prep']['row_id'] ?: null,
                            'product_id' => $meta['prep']['product_id'] ?: null,
                            'bucket_key' => $bucket->bucket_key,
                            'external_id' => $meta['prep']['external_id'],
                            'payload_hash' => $meta['payload_hash'] ?? null,
                        ]
                    );

                    $actions[] = [
                        'action' => $meta['op'] === 'add' ? 'created_spa_item' : 'updated_spa_item',
                        'spaType' => $spaTypeId,
                        'spaId' => $itemId,
                        'product' => $meta['prep']['product_name'],
                    ];
                }
            }
        }

        // Delete orphaned SPA items (removed from entity product rows)
        $orphanDeleteCmds = [];
        foreach ($activeSpaBuckets as $bucket) {
            $bKey = $bucket->bucket_key;
            $spaTypeId = (int) $bucket->spa_entity_type_id;
            $newIds = array_values(array_unique($linkedIdsByBucket[$bKey] ?? []));
            $linkedIdsByBucket[$bKey] = $newIds;

            $oldIds = array_unique(array_merge(
                $currentIdsByBucket[$bKey] ?? [],
                $existingLinks->where('bucket_key', $bKey)->pluck('spa_item_id')->map(fn ($id) => (int) $id)->all()
            ));

            foreach (array_diff($oldIds, $newIds) as $orphanId) {
                $orphanId = (int) $orphanId;
                if ($orphanId <= 0) {
                    continue;
                }
                Cache::put(self::spaSelfWriteCacheKey($tenant->id, $spaTypeId, $orphanId), true, 15);
                $orphanDeleteCmds["orph_{$spaTypeId}_{$orphanId}"] = [
                    'method' => 'crm.item.delete',
                    'params' => ['entityTypeId' => $spaTypeId, 'id' => $orphanId],
                ];
                SyncedSpaLink::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('spa_entity_type_id', $spaTypeId)
                    ->where('spa_item_id', $orphanId)
                    ->delete();
                $actions[] = ['action' => 'deleted_orphan_spa_item', 'spaType' => $spaTypeId, 'spaId' => $orphanId];
            }
        }

        if ($orphanDeleteCmds !== []) {
            $this->client->batch($tenant, $orphanDeleteCmds);
        }

        return $linkedIdsByBucket;
    }

    private function buildSpaItemPayload(
        Tenant $tenant,
        TenantCatalogConfig $catalogConfig,
        TenantSpaBucket $bucket,
        array $prep,
        string $currencyId,
        ?Collection $enumMappings = null,
    ): array {
        $spaTypeId = (int) $bucket->spa_entity_type_id;
        $fields = [
            $bucket->resolveFieldCode('title', 'title') => $prep['product_name'],
            $bucket->resolveFieldCode('xml_id', 'xmlId') => $prep['external_id'],
            $bucket->resolveFieldCode('price', 'opportunity') => $prep['amount'],
            'currencyId' => $currencyId,
        ];

        if ($qtyField = $bucket->resolveFieldCode('quantity')) {
            $fields[$qtyField] = $prep['quantity'];
        }

        $resolveEnum = function (string $fieldCode, ?string $logicalKey) use ($tenant, $spaTypeId, $enumMappings): ?string {
            if (! $logicalKey) {
                return null;
            }
            if ($enumMappings !== null) {
                return $enumMappings->first(function ($m) use ($spaTypeId, $fieldCode, $logicalKey) {
                    return (int) $m->spa_entity_type_id === $spaTypeId
                        && (string) $m->spa_field_code === $fieldCode
                        && (string) $m->logical_key === $logicalKey;
                })?->spa_enum_id;
            }

            return $tenant->enumMappings()
                ->where('spa_entity_type_id', $spaTypeId)
                ->where('spa_field_code', $fieldCode)
                ->where('logical_key', $logicalKey)
                ->value('spa_enum_id');
        };

        if (($costField = $bucket->resolveFieldCode('cost_type')) && $prep['cost_type_enum_id'] !== null) {
            $fields[$costField] = $this->adapter->mapCatalogEnumToSpaEnum($tenant, $spaTypeId, $costField, $prep['cost_type_enum_id']);
        } elseif (($costField = $bucket->resolveFieldCode('cost_type')) && $prep['cost_type_key']) {
            $mapped = $resolveEnum($costField, $prep['cost_type_key']);
            if ($mapped) {
                $fields[$costField] = $mapped;
            }
        }

        if (($optField = $bucket->resolveFieldCode('option_group')) && $prep['option_enum_id'] !== null) {
            $fields[$optField] = $this->adapter->mapCatalogEnumToSpaEnum($tenant, $spaTypeId, $optField, $prep['option_enum_id']);
        } elseif (($optField = $bucket->resolveFieldCode('option_group')) && $prep['option_key']) {
            $mapped = $resolveEnum($optField, $prep['option_key']);
            if ($mapped) {
                $fields[$optField] = $mapped;
            }
        }

        if (($payField = $bucket->resolveFieldCode('payment_schedule')) && $prep['payment_enum_id'] !== null) {
            $fields[$payField] = $this->adapter->mapCatalogEnumToSpaEnum($tenant, $spaTypeId, $payField, $prep['payment_enum_id']);
        } elseif (($payField = $bucket->resolveFieldCode('payment_schedule')) && $prep['payment_key']) {
            $mapped = $resolveEnum($payField, $prep['payment_key']);
            if ($mapped) {
                $fields[$payField] = $mapped;
            }
        }

        foreach ((array) ($catalogConfig->extra_attributes_json ?? []) as $attr) {
            $attrKey = $attr['key'] ?? '';
            $spaField = $bucket->resolveFieldCode('attr:'.$attrKey);
            if (! $spaField) {
                continue;
            }
            $rawVal = $prep['widget_extra'][$attrKey]
                ?? $this->adapter->extractCatalogPropertyValue($prep['catalog_product'], $attr['prop_code'] ?? null);
            if ($rawVal !== null && $rawVal !== '') {
                $fields[$spaField] = ($attr['property_type'] ?? 'S') === 'L'
                    ? $this->adapter->mapCatalogEnumToSpaEnum($tenant, $spaTypeId, $spaField, $rawVal)
                    : $rawVal;
            }
        }

        return $fields;
    }

    private function updateEntityMergedFields(
        Tenant $tenant,
        TenantEntityConfig $entityConfig,
        string $currencyId,
        float $totalWithTax,
        array $bucketSubtotals,
        array $optionTotals,
        array $optionMetaOverrides,
        array $linkedIdsByBucket,
        int $entityId,
        array &$actions,
        array &$errors,
    ): void {
        $isClassic = in_array($entityConfig->entity_type_id, [1, 2], true);

        $fields = $isClassic ? [
            'OPPORTUNITY' => round($totalWithTax, 2),
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            'CURRENCY_ID' => $currencyId,
        ] : [
            'opportunity' => round($totalWithTax, 2),
            'isManualOpportunity' => 'Y',
            'currencyId' => $currencyId,
        ];

        foreach ($entityConfig->fieldMappings as $mapping) {
            switch ($mapping->role) {
                case TenantFieldMapping::ROLE_SUBTOTAL:
                    $amt = (float) ($bucketSubtotals[$mapping->role_qualifier] ?? 0.0);
                    $fields[$mapping->b24_field_code] = $this->adapter->formatMoney($amt, $currencyId);
                    break;

                case TenantFieldMapping::ROLE_OPTION_TOTAL:
                    $amt = (float) ($optionTotals[$mapping->role_qualifier] ?? 0.0);
                    $fields[$mapping->b24_field_code] = $this->adapter->formatMoney($amt, $currencyId);
                    break;

                case TenantFieldMapping::ROLE_SPA_LINK:
                    if (array_key_exists($mapping->role_qualifier, $linkedIdsByBucket)) {
                        $fields[$mapping->b24_field_code] = $this->adapter->formatCrmBindingValue(
                            $linkedIdsByBucket[$mapping->role_qualifier],
                            $mapping
                        );
                    }
                    break;

                case TenantFieldMapping::ROLE_OPTION_META:
                    if (array_key_exists($mapping->role_qualifier, $optionMetaOverrides)) {
                        $fields[$mapping->b24_field_code] = (string) ($optionMetaOverrides[$mapping->role_qualifier] ?? '');
                    }
                    break;

                case TenantFieldMapping::ROLE_SYNC_STATUS:
                    $fields[$mapping->b24_field_code] = 'Done';
                    break;
            }
        }

        Cache::put(self::selfWriteCacheKey($tenant->id, $entityConfig->entity_type_id, $entityId), true, 15);

        try {
            if ($entityConfig->entity_type_id === 1) {
                $this->client->call($tenant, 'crm.lead.update', [
                    'id' => $entityId,
                    'fields' => $fields,
                    'params' => ['REGISTER_SONET_EVENT' => 'N'],
                ]);
            } elseif ($entityConfig->entity_type_id === 2) {
                $this->client->call($tenant, 'crm.deal.update', [
                    'id' => $entityId,
                    'fields' => $fields,
                    'params' => ['REGISTER_SONET_EVENT' => 'N'],
                ]);
            } else {
                $this->client->call($tenant, 'crm.item.update', [
                    'entityTypeId' => $entityConfig->entity_type_id,
                    'id' => $entityId,
                    'fields' => $fields,
                    'useOriginalUfNames' => 'Y',
                ]);
            }

            $actions[] = ['action' => 'updated_entity_fields_atomic', 'fieldCount' => count($fields)];
        } catch (BitrixApiException $e) {
            $errors[] = 'Failed updating entity fields: '.$e->getMessage();
        }
    }

    /**
     * Batch lookup unresolved external IDs across active SPA buckets in a single API round-trip.
     *
     * @param  list<string>  $externalIds
     * @return array<string, array{spa_entity_type_id: int, spa_item_id: int}>
     */
    private function batchLookupSpaItemsByExternalIds(Tenant $tenant, $buckets, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $commands = [];
        foreach ($buckets as $bucket) {
            if (! $bucket->spa_entity_type_id) {
                continue;
            }
            $spaTypeId = (int) $bucket->spa_entity_type_id;
            $commands["lk_{$spaTypeId}"] = [
                'method' => 'crm.item.list',
                'params' => [
                    'entityTypeId' => $spaTypeId,
                    'filter' => ['@xmlId' => $externalIds],
                    'select' => ['id', 'xmlId'],
                ],
            ];
        }

        if ($commands === []) {
            return [];
        }

        try {
            $batchRes = $this->client->batch($tenant, $commands);
            $foundMap = [];
            foreach ($buckets as $bucket) {
                $spaTypeId = (int) $bucket->spa_entity_type_id;
                $cmdKey = "lk_{$spaTypeId}";
                $items = (array) ($batchRes[$cmdKey]['result']['items'] ?? []);
                foreach ($items as $item) {
                    $xmlId = (string) ($item['xmlId'] ?? '');
                    $itemId = (int) ($item['id'] ?? 0);
                    if ($xmlId !== '' && $itemId > 0 && ! isset($foundMap[$xmlId])) {
                        $foundMap[$xmlId] = [
                            'spa_entity_type_id' => $spaTypeId,
                            'spa_item_id' => $itemId,
                        ];
                    }
                }
            }

            return $foundMap;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return ?array{spa_entity_type_id: int, spa_item_id: int}
     */
    private function findSpaItemByXmlIdAcrossBuckets(Tenant $tenant, $buckets, string $xmlId): ?array
    {
        foreach ($buckets as $bucket) {
            if (! $bucket->spa_entity_type_id) {
                continue;
            }
            try {
                $res = $this->client->call($tenant, 'crm.item.list', [
                    'entityTypeId' => (int) $bucket->spa_entity_type_id,
                    'filter' => ['=xmlId' => $xmlId],
                    'select' => ['id', 'xmlId'],
                ]);
                $item = $res['result']['items'][0] ?? null;
                if ($item && ! empty($item['id'])) {
                    return [
                        'spa_entity_type_id' => (int) $bucket->spa_entity_type_id,
                        'spa_item_id' => (int) $item['id'],
                    ];
                }
            } catch (BitrixApiException) {
                continue;
            }
        }

        return null;
    }

    private function matchSpaBucket($activeSpaBuckets, ?string $costTypeKey, ?string $optionKey, ?string $bucketKey): ?TenantSpaBucket
    {
        if ($bucketKey) {
            $exact = $activeSpaBuckets->firstWhere('bucket_key', $bucketKey);
            if ($exact) {
                return $exact;
            }
        }

        if ($costTypeKey) {
            $byCost = $activeSpaBuckets->first(fn (TenantSpaBucket $b) => $b->cost_type_key === $costTypeKey && ! $b->option_key);
            if ($byCost) {
                return $byCost;
            }
        }

        return $activeSpaBuckets->firstWhere('bucket_key', 'consolidated_all') ?: $activeSpaBuckets->first();
    }

    private function bucketKey(string $costTypeKey, string $optionKey): string
    {
        $shortCost = match ($costTypeKey) {
            'professional' => 'prof',
            'government' => 'gov',
            default => substr(Str::snake($costTypeKey), 0, 8),
        };
        $shortOpt = str_replace('option_', 'opt', Str::snake($optionKey));

        return "{$shortCost}_{$shortOpt}";
    }

    private function buildChoiceList($enumRows, string $group, string $attrKey, array $configItems): array
    {
        $rows = $enumRows->where('mapping_group', $group)->where('attr_key', $attrKey);
        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [
                'logical_key' => $r->logical_key,
                'label' => $r->logical_label,
                'catalog_enum_id' => $r->catalog_enum_id,
            ])->values()->all();
        }

        return collect($configItems)
            ->filter(fn (array $i) => (bool) ($i['is_enabled'] ?? true))
            ->map(fn (array $i) => [
                'logical_key' => $i['key'],
                'label' => $i['label'],
                'catalog_enum_id' => null,
            ])->values()->all();
    }

    private function buildPaymentChoiceList($enumRows): array
    {
        $rows = $enumRows->where('mapping_group', 'payment_schedule');
        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [
                'logical_key' => $r->logical_key,
                'label' => $r->logical_label,
                'catalog_enum_id' => $r->catalog_enum_id,
            ])->values()->all();
        }

        return collect(TenantCatalogConfig::defaultPaymentSchedules())->map(fn (array $p) => [
            'logical_key' => $p['key'],
            'label' => $p['label'],
            'catalog_enum_id' => null,
        ])->all();
    }

    private function writeSyncLog(
        Tenant $tenant,
        ?int $entityTypeId,
        ?int $entityId,
        string $triggerSource,
        string $status,
        float $startTime,
        array $actions,
        array $errors,
    ): void {
        $metrics = $this->client->getMetrics();

        SyncLog::create([
            'tenant_id' => $tenant->id,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'trigger_source' => $triggerSource,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
            'api_calls_count' => $metrics['calls'],
            'actions_count' => count($actions),
            'actions_json' => $actions,
            'errors_json' => $errors,
            'operating_max' => $metrics['operating_max'],
            'created_at' => now(),
        ]);
    }
}
