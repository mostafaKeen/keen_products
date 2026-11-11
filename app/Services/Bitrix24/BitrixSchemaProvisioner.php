<?php

namespace App\Services\Bitrix24;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Models\TenantEntityConfig;
use App\Models\TenantEnumMapping;
use App\Models\TenantFieldMapping;
use App\Models\TenantSpaBucket;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BitrixSchemaProvisioner
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixFieldAdapter $adapter,
        private readonly BitrixSchemaScanner $scanner,
        private readonly BitrixPlacementEventSyncer $syncer,
        private readonly SubscriptionGate $gate,
    ) {}

    /**
     * One-click idempotent schema provisioning in a Bitrix24 portal.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function provisionAuto(Tenant $tenant, array $input = []): array
    {
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        $maxOptions = (int) ($subEval['max_options'] ?? 2);
        $allowCustomSpas = (bool) ($subEval['features']['allow_custom_spas'] ?? true);
        $allowCustomAttrs = (bool) ($subEval['features']['allow_custom_product_attributes'] ?? true);

        $mode = (string) ($input['fee_sync_mode'] ?? $tenant->fee_sync_mode ?: Tenant::MODE_DEDICATED_SPAS);
        if (! in_array($mode, [Tenant::MODE_DEDICATED_SPAS, Tenant::MODE_CONSOLIDATED_SPAS, Tenant::MODE_SUBTOTALS_ONLY], true)) {
            $mode = Tenant::MODE_DEDICATED_SPAS;
        }

        $optionsConfig = $this->normalizeOptionsConfig($input['options_config'] ?? TenantCatalogConfig::defaultOptionsConfig(), $maxOptions);
        $costTypesConfig = $this->normalizeCostTypesConfig($input['cost_types_config'] ?? TenantCatalogConfig::defaultCostTypesConfig());
        $optionMetaSchema = $this->normalizeMetaSchema($input['option_meta_schema'] ?? TenantCatalogConfig::defaultOptionMetaSchema());
        $extraAttributes = $allowCustomAttrs ? (array) ($input['extra_attributes'] ?? []) : [];
        $targetEntityIds = array_values(array_unique(array_map('intval', $input['entity_type_ids'] ?? [1, 2])));

        if (! $allowCustomSpas) {
            $targetEntityIds = array_values(array_filter($targetEntityIds, fn (int $id) => in_array($id, [1, 2, 7], true)));
        }

        $log = [];
        $warnings = [];

        // 1. Primary Product Catalog & Properties
        $iblockInfo = $this->resolvePrimaryCatalog($tenant);
        $iblockId = $iblockInfo['iblock_id'];
        $existingProps = $this->listCatalogProperties($tenant, $iblockId);

        $costTypeProp = $this->ensureListCatalogProperty(
            $tenant,
            $iblockId,
            $existingProps,
            'INLEX_COST_TYPE',
            'Type of Cost',
            collect($costTypesConfig)->map(fn (array $ct, int $idx) => [
                'key' => $ct['key'],
                'label' => $ct['label'],
                'xml_id' => 'INLEX_COST_'.strtoupper($ct['key']),
                'sort' => ($idx + 1) * 100,
            ])->all(),
            $log
        );

        $optionProp = $this->ensureListCatalogProperty(
            $tenant,
            $iblockId,
            $existingProps,
            'INLEX_OPTION',
            'Option',
            collect($optionsConfig)->map(fn (array $opt, int $idx) => [
                'key' => $opt['key'],
                'label' => $opt['label'],
                'xml_id' => 'INLEX_'.strtoupper($opt['key']),
                'sort' => ($idx + 1) * 100,
            ])->all(),
            $log
        );

        $paymentDefaults = collect(TenantCatalogConfig::defaultPaymentSchedules())->map(fn (array $p, int $idx) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'xml_id' => $p['xml_id'],
            'sort' => ($idx + 1) * 100,
        ])->all();

        $paymentsProp = $this->ensureListCatalogProperty(
            $tenant,
            $iblockId,
            $existingProps,
            'INLEX_PAYMENTS',
            'Payments',
            $paymentDefaults,
            $log
        );

        // Optional extra product attributes
        $normalizedExtras = [];
        foreach ($extraAttributes as $attr) {
            $attrKey = Str::snake((string) ($attr['key'] ?? $attr['label'] ?? ''));
            $attrLabel = trim((string) ($attr['label'] ?? ''));
            if ($attrKey === '' || $attrLabel === '') {
                continue;
            }
            $xmlCode = 'INLEX_ATTR_'.strtoupper(substr(preg_replace('/[^A-Z0-9_]/i', '', $attrKey), 0, 20));
            $propType = strtoupper((string) ($attr['property_type'] ?? 'S')) === 'L' ? 'L' : 'S';

            $enumDefs = [];
            if ($propType === 'L') {
                foreach ((array) ($attr['options'] ?? []) as $oIdx => $optItem) {
                    $lbl = is_string($optItem) ? trim($optItem) : trim((string) ($optItem['label'] ?? ''));
                    if ($lbl === '') {
                        continue;
                    }
                    $enumDefs[] = [
                        'key' => Str::snake($lbl),
                        'label' => $lbl,
                        'xml_id' => $xmlCode.'_'.strtoupper(Str::snake($lbl)),
                        'sort' => ($oIdx + 1) * 100,
                    ];
                }
            }

            $ensuredExtra = $propType === 'L'
                ? $this->ensureListCatalogProperty($tenant, $iblockId, $existingProps, $xmlCode, $attrLabel, $enumDefs, $log)
                : $this->ensureScalarCatalogProperty($tenant, $iblockId, $existingProps, $xmlCode, $attrLabel, 'S', $log);

            $normalizedExtras[] = [
                'key' => $attrKey,
                'label' => $attrLabel,
                'prop_code' => $ensuredExtra['prop_code'],
                'prop_id' => $ensuredExtra['prop_id'],
                'property_type' => $propType,
                'multiple' => false,
                'sync_to_spa' => (bool) ($attr['sync_to_spa'] ?? true),
                'enum_options' => $ensuredExtra['enums'] ?? [],
            ];
        }

        // 2. Fee SPAs (according to chosen fee_sync_mode)
        $portalSpas = $this->listPortalTypes($tenant);
        $spaBucketsData = [];

        if ($mode === Tenant::MODE_DEDICATED_SPAS) {
            foreach ($optionsConfig as $opt) {
                if (empty($opt['is_enabled'])) {
                    continue;
                }
                foreach ($costTypesConfig as $ct) {
                    if (empty($ct['is_enabled'])) {
                        continue;
                    }
                    $bucketKey = $this->bucketKey($ct['key'], $opt['key']);
                    $spaTitle = "{$ct['label']} - {$opt['label']}";

                    try {
                        $spa = $this->ensureFeeSpa($tenant, $portalSpas, $spaTitle, $log);
                        $spaSetup = $this->provisionSpaFields(
                            $tenant,
                            $spa['id'],
                            $spa['entityTypeId'],
                            $costTypesConfig,
                            $optionsConfig,
                            $paymentDefaults,
                            $normalizedExtras,
                            $log
                        );
                        $spaBucketsData[] = [
                            'bucket_key' => $bucketKey,
                            'title' => $spaTitle,
                            'cost_type_key' => $ct['key'],
                            'option_key' => $opt['key'],
                            'spa_id' => $spa['id'],
                            'spa_entity_type_id' => $spa['entityTypeId'],
                            'field_map' => $spaSetup['field_map'],
                            'enum_rows' => $spaSetup['enum_rows'],
                        ];
                    } catch (BitrixApiException $e) {
                        if ($e->isSpaRestricted()) {
                            if ($spaBucketsData === []) {
                                $warnings[] = 'Bitrix24 plan SPA limit reached. Falling back cleanly to Subtotals-Only mode.';
                                $mode = Tenant::MODE_SUBTOTALS_ONLY;
                            } else {
                                $warnings[] = "Bitrix24 plan SPA limit reached while creating '{$spaTitle}'. Switch to Consolidated SPA or Subtotals-Only mode.";
                            }
                            break 2;
                        }
                        throw $e;
                    }
                }
            }
        } elseif ($mode === Tenant::MODE_CONSOLIDATED_SPAS) {
            $spaTitle = 'Fee Breakdown Items';
            try {
                $spa = $this->ensureFeeSpa($tenant, $portalSpas, $spaTitle, $log);
                $spaSetup = $this->provisionSpaFields(
                    $tenant,
                    $spa['id'],
                    $spa['entityTypeId'],
                    $costTypesConfig,
                    $optionsConfig,
                    $paymentDefaults,
                    $normalizedExtras,
                    $log
                );
                $spaBucketsData[] = [
                    'bucket_key' => 'consolidated_all',
                    'title' => $spaTitle,
                    'cost_type_key' => null,
                    'option_key' => null,
                    'spa_id' => $spa['id'],
                    'spa_entity_type_id' => $spa['entityTypeId'],
                    'field_map' => $spaSetup['field_map'],
                    'enum_rows' => $spaSetup['enum_rows'],
                ];
            } catch (BitrixApiException $e) {
                if ($e->isSpaRestricted()) {
                    $warnings[] = 'Bitrix24 SPA limit reached. Falling back cleanly to Subtotals-Only mode.';
                    $mode = Tenant::MODE_SUBTOTALS_ONLY;
                } else {
                    throw $e;
                }
            }
        }

        // 3. Target CRM Entities & Custom Fields
        $bucketCombinations = $this->computeBucketCombinations($optionsConfig, $costTypesConfig);
        $entityConfigsData = [];

        foreach ($targetEntityIds as $etId) {
            $meta = $this->resolveTargetEntityMeta($etId, $portalSpas);
            if (! $meta) {
                $warnings[] = "Target entityTypeId={$etId} was not found on this portal and was skipped.";

                continue;
            }

            $existingFields = $this->listEntityFields($tenant, $etId, $meta['spa_id']);
            $mappings = [];

            // 3a. SPA link fields (one per provisioned SPA bucket)
            foreach ($spaBucketsData as $bucket) {
                $suffix = 'INLEX_LNK_'.strtoupper(substr(preg_replace('/[^A-Z0-9_]/i', '', $bucket['bucket_key']), 0, 18));
                $field = $this->ensureEntityField($tenant, $etId, $meta['spa_id'], $existingFields, [
                    'suffix' => $suffix,
                    'xml_id' => $suffix,
                    'label' => $bucket['title'].' Items',
                    'user_type_id' => 'crm',
                    'multiple' => 'Y',
                    'settings' => ["DYNAMIC_{$bucket['spa_entity_type_id']}" => 'Y'],
                ], $log);

                $mappings[] = [
                    'role' => TenantFieldMapping::ROLE_SPA_LINK,
                    'role_qualifier' => $bucket['bucket_key'],
                    'b24_field_code' => $field['code'],
                    'b24_field_type' => 'crm',
                    'b24_settings_json' => ["DYNAMIC_{$bucket['spa_entity_type_id']}" => 'Y'],
                ];
            }

            // 3b. Subtotal money fields (one per costType x option)
            foreach ($bucketCombinations as $combo) {
                $suffix = 'INLEX_SUB_'.strtoupper(substr(preg_replace('/[^A-Z0-9_]/i', '', $combo['bucket_key']), 0, 18));
                $field = $this->ensureEntityField($tenant, $etId, $meta['spa_id'], $existingFields, [
                    'suffix' => $suffix,
                    'xml_id' => $suffix,
                    'label' => "Subtotal - {$combo['title']}",
                    'user_type_id' => 'money',
                    'multiple' => 'N',
                    'settings' => [],
                ], $log);

                $mappings[] = [
                    'role' => TenantFieldMapping::ROLE_SUBTOTAL,
                    'role_qualifier' => $combo['bucket_key'],
                    'b24_field_code' => $field['code'],
                    'b24_field_type' => 'money',
                    'b24_settings_json' => [],
                ];
            }

            // 3c. Option Total money fields
            foreach ($optionsConfig as $opt) {
                if (empty($opt['is_enabled'])) {
                    continue;
                }
                $suffix = 'INLEX_TOT_'.strtoupper(substr(preg_replace('/[^A-Z0-9_]/i', '', $opt['key']), 0, 18));
                $field = $this->ensureEntityField($tenant, $etId, $meta['spa_id'], $existingFields, [
                    'suffix' => $suffix,
                    'xml_id' => $suffix,
                    'label' => "{$opt['label']} - Total",
                    'user_type_id' => 'money',
                    'multiple' => 'N',
                    'settings' => [],
                ], $log);

                $mappings[] = [
                    'role' => TenantFieldMapping::ROLE_OPTION_TOTAL,
                    'role_qualifier' => $opt['key'],
                    'b24_field_code' => $field['code'],
                    'b24_field_type' => 'money',
                    'b24_settings_json' => [],
                ];

                // 3d. Option Metadata fields
                foreach ($optionMetaSchema as $metaField) {
                    $mKey = $metaField['key'];
                    $mSuffix = 'INLEX_M_'.strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $opt['key'].$mKey), 0, 22));
                    $mXml = strtoupper("INLEX_{$opt['key']}_{$mKey}");
                    $field = $this->ensureEntityField($tenant, $etId, $meta['spa_id'], $existingFields, [
                        'suffix' => $mSuffix,
                        'xml_id' => $mXml,
                        'label' => "{$opt['label']} - {$metaField['label']}",
                        'user_type_id' => 'string',
                        'multiple' => 'N',
                        'settings' => [],
                    ], $log);

                    $mappings[] = [
                        'role' => TenantFieldMapping::ROLE_OPTION_META,
                        'role_qualifier' => "{$opt['key']}.{$mKey}",
                        'b24_field_code' => $field['code'],
                        'b24_field_type' => 'string',
                        'b24_settings_json' => [],
                    ];
                }
            }

            // 3e. Sync status marker field
            $statusField = $this->ensureEntityField($tenant, $etId, $meta['spa_id'], $existingFields, [
                'suffix' => 'INLEX_SYNC_STATUS',
                'xml_id' => 'INLEX_SYNC_STATUS',
                'label' => 'Fee Sync Status',
                'user_type_id' => 'string',
                'multiple' => 'N',
                'settings' => [],
            ], $log);

            $mappings[] = [
                'role' => TenantFieldMapping::ROLE_SYNC_STATUS,
                'role_qualifier' => 'default',
                'b24_field_code' => $statusField['code'],
                'b24_field_type' => 'string',
                'b24_settings_json' => [],
            ];

            $entityConfigsData[] = [
                'logical_key' => $meta['logical_key'],
                'title' => $meta['title'],
                'entity_type_id' => $etId,
                'owner_type_code' => TenantEntityConfig::ownerCodeForEntityTypeId($etId),
                'placement_code' => TenantEntityConfig::placementCodeForEntityTypeId($etId),
                'mappings' => $mappings,
            ];
        }

        // 4. Persist all Tenant Schema Tables atomically
        DB::transaction(function () use (
            $tenant,
            $mode,
            $iblockId,
            $iblockInfo,
            $costTypeProp,
            $optionProp,
            $paymentsProp,
            $optionsConfig,
            $costTypesConfig,
            $optionMetaSchema,
            $normalizedExtras,
            $spaBucketsData,
            $entityConfigsData
        ) {
            TenantCatalogConfig::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'iblock_id' => $iblockId,
                    'catalog_name' => $iblockInfo['name'],
                    'cost_type_prop_code' => $costTypeProp['prop_code'],
                    'cost_type_prop_id' => $costTypeProp['prop_id'],
                    'option_prop_code' => $optionProp['prop_code'],
                    'option_prop_id' => $optionProp['prop_id'],
                    'payments_prop_code' => $paymentsProp['prop_code'],
                    'payments_prop_id' => $paymentsProp['prop_id'],
                    'extra_attributes_json' => $normalizedExtras,
                    'options_config_json' => $optionsConfig,
                    'cost_types_config_json' => $costTypesConfig,
                    'option_meta_schema_json' => $optionMetaSchema,
                ]
            );

            // Rebuild enum mappings cleanly
            TenantEnumMapping::where('tenant_id', $tenant->id)->delete();

            foreach ($costTypeProp['enums'] as $e) {
                TenantEnumMapping::create([
                    'tenant_id' => $tenant->id,
                    'mapping_group' => 'cost_type',
                    'attr_key' => 'cost_type',
                    'logical_key' => $e['key'],
                    'logical_label' => $e['label'],
                    'catalog_prop_code' => $costTypeProp['prop_code'],
                    'catalog_enum_id' => $e['id'],
                    'catalog_enum_xml_id' => $e['xml_id'],
                    'spa_entity_type_id' => 0,
                ]);
            }
            foreach ($optionProp['enums'] as $e) {
                TenantEnumMapping::create([
                    'tenant_id' => $tenant->id,
                    'mapping_group' => 'option_group',
                    'attr_key' => 'option_group',
                    'logical_key' => $e['key'],
                    'logical_label' => $e['label'],
                    'catalog_prop_code' => $optionProp['prop_code'],
                    'catalog_enum_id' => $e['id'],
                    'catalog_enum_xml_id' => $e['xml_id'],
                    'spa_entity_type_id' => 0,
                ]);
            }
            foreach ($paymentsProp['enums'] as $e) {
                TenantEnumMapping::create([
                    'tenant_id' => $tenant->id,
                    'mapping_group' => 'payment_schedule',
                    'attr_key' => 'payment_schedule',
                    'logical_key' => $e['key'],
                    'logical_label' => $e['label'],
                    'catalog_prop_code' => $paymentsProp['prop_code'],
                    'catalog_enum_id' => $e['id'],
                    'catalog_enum_xml_id' => $e['xml_id'],
                    'spa_entity_type_id' => 0,
                ]);
            }

            // Lookup maps for linking catalog enum IDs to SPA enum IDs
            $catEnumsByGroup = [
                'cost_type' => collect($costTypeProp['enums'])->keyBy('key'),
                'option_group' => collect($optionProp['enums'])->keyBy('key'),
                'payment_schedule' => collect($paymentsProp['enums'])->keyBy('key'),
            ];
            foreach ($normalizedExtras as $extra) {
                if (($extra['property_type'] ?? '') === 'L') {
                    $catEnumsByGroup[$extra['key']] = collect($extra['enum_options'])->keyBy('key');
                    foreach ($extra['enum_options'] as $e) {
                        TenantEnumMapping::create([
                            'tenant_id' => $tenant->id,
                            'mapping_group' => 'custom_attr',
                            'attr_key' => $extra['key'],
                            'logical_key' => $e['key'],
                            'logical_label' => $e['label'],
                            'catalog_prop_code' => $extra['prop_code'],
                            'catalog_enum_id' => $e['id'],
                            'catalog_enum_xml_id' => $e['xml_id'],
                            'spa_entity_type_id' => 0,
                        ]);
                    }
                }
            }

            TenantSpaBucket::where('tenant_id', $tenant->id)->delete();
            foreach ($spaBucketsData as $b) {
                TenantSpaBucket::create([
                    'tenant_id' => $tenant->id,
                    'bucket_key' => $b['bucket_key'],
                    'title' => $b['title'],
                    'cost_type_key' => $b['cost_type_key'],
                    'option_key' => $b['option_key'],
                    'spa_id' => $b['spa_id'],
                    'spa_entity_type_id' => $b['spa_entity_type_id'],
                    'is_active' => true,
                    'field_map_json' => $b['field_map'],
                ]);

                foreach ($b['enum_rows'] as $row) {
                    $catEnum = $catEnumsByGroup[$row['attr_key']][$row['logical_key']] ?? null;
                    TenantEnumMapping::create([
                        'tenant_id' => $tenant->id,
                        'mapping_group' => $row['mapping_group'],
                        'attr_key' => $row['attr_key'],
                        'logical_key' => $row['logical_key'],
                        'logical_label' => $row['logical_label'],
                        'catalog_prop_code' => $catEnum ? match ($row['attr_key']) {
                            'cost_type' => $costTypeProp['prop_code'],
                            'option_group' => $optionProp['prop_code'],
                            'payment_schedule' => $paymentsProp['prop_code'],
                            default => collect($normalizedExtras)->firstWhere('key', $row['attr_key'])['prop_code'] ?? null,
                        } : null,
                        'catalog_enum_id' => $catEnum['id'] ?? null,
                        'catalog_enum_xml_id' => $catEnum['xml_id'] ?? null,
                        'spa_entity_type_id' => $b['spa_entity_type_id'],
                        'spa_field_code' => $row['spa_field_code'],
                        'spa_enum_id' => $row['spa_enum_id'],
                    ]);
                }
            }

            TenantFieldMapping::where('tenant_id', $tenant->id)->delete();
            TenantEntityConfig::where('tenant_id', $tenant->id)->delete();

            foreach ($entityConfigsData as $ecData) {
                $ec = TenantEntityConfig::create([
                    'tenant_id' => $tenant->id,
                    'logical_key' => $ecData['logical_key'],
                    'title' => $ecData['title'],
                    'entity_type_id' => $ecData['entity_type_id'],
                    'owner_type_code' => $ecData['owner_type_code'],
                    'placement_code' => $ecData['placement_code'],
                    'is_enabled' => true,
                    'auto_sync_enabled' => true,
                ]);

                foreach ($ecData['mappings'] as $m) {
                    TenantFieldMapping::create([
                        'tenant_id' => $tenant->id,
                        'entity_config_id' => $ec->id,
                        'role' => $m['role'],
                        'role_qualifier' => $m['role_qualifier'],
                        'b24_field_code' => $m['b24_field_code'],
                        'b24_field_type' => $m['b24_field_type'],
                        'b24_settings_json' => $m['b24_settings_json'],
                    ]);
                }
            }

            $tenant->forceFill([
                'fee_sync_mode' => $mode,
                'schema_status' => 'provisioned',
            ])->save();
        });

        // 5. Bind Placements & Events
        $bindingResult = $this->syncer->sync(
            $tenant->fresh(),
            (bool) ($subEval['features']['allow_auto_background_sync'] ?? true)
        );

        return [
            'success' => true,
            'fee_sync_mode' => $mode,
            'log' => $log,
            'warnings' => array_merge($warnings, $bindingResult['warnings'] ?? []),
            'bindings' => $bindingResult,
        ];
    }

    /**
     * Save custom portal-mapped schemas (for portals with existing fields & enum IDs).
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveCustomMappings(Tenant $tenant, array $payload): array
    {
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        $maxOptions = (int) ($subEval['max_options'] ?? 2);
        $allowCustomSpas = (bool) ($subEval['features']['allow_custom_spas'] ?? true);

        $mode = (string) ($payload['fee_sync_mode'] ?? $tenant->fee_sync_mode ?: Tenant::MODE_DEDICATED_SPAS);
        $catalog = (array) ($payload['catalog'] ?? []);
        $optionsConfig = $this->normalizeOptionsConfig($payload['options_config'] ?? TenantCatalogConfig::defaultOptionsConfig(), $maxOptions);
        $costTypesConfig = $this->normalizeCostTypesConfig($payload['cost_types_config'] ?? TenantCatalogConfig::defaultCostTypesConfig());
        $optionMetaSchema = $this->normalizeMetaSchema($payload['option_meta_schema'] ?? TenantCatalogConfig::defaultOptionMetaSchema());
        $extraAttributes = (array) ($payload['extra_attributes'] ?? []);
        $spaBuckets = (array) ($payload['spa_buckets'] ?? []);
        $entities = (array) ($payload['entities'] ?? []);
        $enumMappings = (array) ($payload['enum_mappings'] ?? []);

        DB::transaction(function () use (
            $tenant,
            $mode,
            $catalog,
            $optionsConfig,
            $costTypesConfig,
            $optionMetaSchema,
            $extraAttributes,
            $spaBuckets,
            $entities,
            $enumMappings,
            $allowCustomSpas
        ) {
            TenantCatalogConfig::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'iblock_id' => ! empty($catalog['iblock_id']) ? (int) $catalog['iblock_id'] : null,
                    'catalog_name' => $catalog['catalog_name'] ?? 'CRM Catalog',
                    'cost_type_prop_code' => $catalog['cost_type_prop_code'] ?? null,
                    'cost_type_prop_id' => ! empty($catalog['cost_type_prop_id']) ? (int) $catalog['cost_type_prop_id'] : null,
                    'option_prop_code' => $catalog['option_prop_code'] ?? null,
                    'option_prop_id' => ! empty($catalog['option_prop_id']) ? (int) $catalog['option_prop_id'] : null,
                    'payments_prop_code' => $catalog['payments_prop_code'] ?? null,
                    'payments_prop_id' => ! empty($catalog['payments_prop_id']) ? (int) $catalog['payments_prop_id'] : null,
                    'extra_attributes_json' => $extraAttributes,
                    'options_config_json' => $optionsConfig,
                    'cost_types_config_json' => $costTypesConfig,
                    'option_meta_schema_json' => $optionMetaSchema,
                ]
            );

            TenantSpaBucket::where('tenant_id', $tenant->id)->delete();
            foreach ($spaBuckets as $b) {
                if (empty($b['bucket_key'])) {
                    continue;
                }
                TenantSpaBucket::create([
                    'tenant_id' => $tenant->id,
                    'bucket_key' => (string) $b['bucket_key'],
                    'title' => (string) ($b['title'] ?? $b['bucket_key']),
                    'cost_type_key' => $b['cost_type_key'] ?? null,
                    'option_key' => $b['option_key'] ?? null,
                    'spa_id' => ! empty($b['spa_id']) ? (int) $b['spa_id'] : null,
                    'spa_entity_type_id' => ! empty($b['spa_entity_type_id']) ? (int) $b['spa_entity_type_id'] : null,
                    'is_active' => (bool) ($b['is_active'] ?? true),
                    'field_map_json' => (array) ($b['field_map'] ?? []),
                ]);
            }

            TenantFieldMapping::where('tenant_id', $tenant->id)->delete();
            TenantEntityConfig::where('tenant_id', $tenant->id)->delete();

            foreach ($entities as $entity) {
                $etId = (int) ($entity['entity_type_id'] ?? 0);
                if ($etId <= 0) {
                    continue;
                }
                if (! $allowCustomSpas && ! in_array($etId, [1, 2, 7], true)) {
                    continue;
                }

                $ec = TenantEntityConfig::create([
                    'tenant_id' => $tenant->id,
                    'logical_key' => (string) ($entity['logical_key'] ?? $this->defaultLogicalEntityKey($etId)),
                    'title' => (string) ($entity['title'] ?? "CRM #{$etId}"),
                    'entity_type_id' => $etId,
                    'owner_type_code' => TenantEntityConfig::ownerCodeForEntityTypeId($etId),
                    'placement_code' => TenantEntityConfig::placementCodeForEntityTypeId($etId),
                    'is_enabled' => (bool) ($entity['is_enabled'] ?? true),
                    'auto_sync_enabled' => (bool) ($entity['auto_sync_enabled'] ?? true),
                ]);

                foreach ((array) ($entity['mappings'] ?? []) as $map) {
                    if (empty($map['role']) || empty($map['role_qualifier']) || empty($map['b24_field_code'])) {
                        continue;
                    }
                    TenantFieldMapping::create([
                        'tenant_id' => $tenant->id,
                        'entity_config_id' => $ec->id,
                        'role' => (string) $map['role'],
                        'role_qualifier' => (string) $map['role_qualifier'],
                        'b24_field_code' => (string) $map['b24_field_code'],
                        'b24_field_type' => $map['b24_field_type'] ?? null,
                        'b24_settings_json' => $map['b24_settings_json'] ?? [],
                    ]);
                }
            }

            TenantEnumMapping::where('tenant_id', $tenant->id)->delete();
            foreach ($enumMappings as $em) {
                if (empty($em['mapping_group']) || empty($em['logical_key'])) {
                    continue;
                }
                TenantEnumMapping::create([
                    'tenant_id' => $tenant->id,
                    'mapping_group' => (string) $em['mapping_group'],
                    'attr_key' => (string) ($em['attr_key'] ?? $em['mapping_group']),
                    'logical_key' => (string) $em['logical_key'],
                    'logical_label' => (string) ($em['logical_label'] ?? $em['logical_key']),
                    'catalog_prop_code' => $em['catalog_prop_code'] ?? null,
                    'catalog_enum_id' => isset($em['catalog_enum_id']) ? (string) $em['catalog_enum_id'] : null,
                    'catalog_enum_xml_id' => $em['catalog_enum_xml_id'] ?? null,
                    'spa_entity_type_id' => (int) ($em['spa_entity_type_id'] ?? 0),
                    'spa_field_code' => $em['spa_field_code'] ?? null,
                    'spa_enum_id' => isset($em['spa_enum_id']) ? (string) $em['spa_enum_id'] : null,
                ]);
            }

            $tenant->forceFill([
                'fee_sync_mode' => $mode,
                'schema_status' => 'mapped',
            ])->save();
        });

        $bindingResult = $this->syncer->sync(
            $tenant->fresh(),
            (bool) ($subEval['features']['allow_auto_background_sync'] ?? true)
        );

        return [
            'success' => true,
            'warnings' => $bindingResult['warnings'] ?? [],
            'bindings' => $bindingResult,
        ];
    }

    /**
     * @return array{iblock_id: int, name: string}
     */
    private function resolvePrimaryCatalog(Tenant $tenant): array
    {
        $res = $this->client->call($tenant, 'catalog.catalog.list');
        $catalogs = (array) ($res['result']['catalogs'] ?? []);
        $primary = collect($catalogs)->first(fn (array $c) => empty($c['productIblockId']) || (int) $c['productIblockId'] === 0)
            ?: ($catalogs[0] ?? null);

        if (! $primary) {
            throw new BitrixApiException('CATALOG_NOT_FOUND', 'No Bitrix24 Commercial Catalog was found on this portal.', 422);
        }

        return [
            'iblock_id' => (int) ($primary['iblockId'] ?? $primary['id']),
            'name' => (string) ($primary['name'] ?? $primary['NAME'] ?? 'CRM Catalog'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listCatalogProperties(Tenant $tenant, int $iblockId): array
    {
        $res = $this->client->call($tenant, 'catalog.productProperty.list', [
            'filter' => ['iblockId' => $iblockId],
            'select' => ['id', 'iblockId', 'name', 'code', 'xmlId', 'propertyType', 'multiple', 'userType'],
        ]);

        return (array) ($res['result']['productProperties'] ?? []);
    }

    /**
     * @param  list<array{key: string, label: string, xml_id: string, sort: int}>  $desiredEnums
     * @return array{prop_id: int, prop_code: string, enums: list<array{id: string, key: string, label: string, xml_id: string}>}
     */
    private function ensureListCatalogProperty(
        Tenant $tenant,
        int $iblockId,
        array &$existingProps,
        string $code,
        string $name,
        array $desiredEnums,
        array &$log,
    ): array {
        // Bitrix24 converts single-select L properties with < 2 options into BoolEnum and drops writes.
        if (count($desiredEnums) < 2) {
            $desiredEnums[] = [
                'key' => 'other',
                'label' => 'Other / Standard',
                'xml_id' => $code.'_OTHER',
                'sort' => 900,
            ];
        }

        $matched = collect($existingProps)->first(function (array $p) use ($code, $name) {
            return strtoupper((string) ($p['code'] ?? '')) === strtoupper($code)
                || strtoupper((string) ($p['xmlId'] ?? '')) === strtoupper($code)
                || strcasecmp(trim((string) ($p['name'] ?? '')), $name) === 0;
        });

        if (! $matched) {
            $createRes = $this->client->call($tenant, 'catalog.productProperty.add', [
                'fields' => [
                    'iblockId' => $iblockId,
                    'name' => $name,
                    'code' => $code,
                    'xmlId' => $code,
                    'propertyType' => 'L',
                    'multiple' => 'N',
                    'active' => 'Y',
                    'sort' => 100,
                ],
            ]);
            $matched = $createRes['result']['productProperty'] ?? [];
            $existingProps[] = $matched;
            $log[] = "Created catalog property '{$name}' (property{$matched['id']}).";
        }

        $propId = (int) ($matched['id'] ?? 0);
        $enumListRes = $this->client->call($tenant, 'catalog.productPropertyEnum.list', [
            'filter' => ['propertyId' => $propId],
            'select' => ['id', 'propertyId', 'value', 'xmlId', 'sort'],
        ]);
        $existingEnums = (array) ($enumListRes['result']['productPropertyEnums'] ?? []);

        $resolvedEnums = [];
        foreach ($desiredEnums as $desired) {
            $foundEnum = collect($existingEnums)->first(function (array $e) use ($desired) {
                return strtoupper((string) ($e['xmlId'] ?? '')) === strtoupper($desired['xml_id'])
                    || strcasecmp(trim((string) ($e['value'] ?? '')), $desired['label']) === 0;
            });

            if (! $foundEnum) {
                $addEnumRes = $this->client->call($tenant, 'catalog.productPropertyEnum.add', [
                    'fields' => [
                        'propertyId' => $propId,
                        'value' => $desired['label'],
                        'xmlId' => $desired['xml_id'],
                        'sort' => $desired['sort'],
                        'def' => 'N',
                    ],
                ]);
                $foundEnum = $addEnumRes['result']['productPropertyEnum'] ?? [];
                $existingEnums[] = $foundEnum;
                $log[] = "Added enum '{$desired['label']}' to '{$name}'.";
            }

            $resolvedEnums[] = [
                'id' => (string) ($foundEnum['id'] ?? ''),
                'key' => $desired['key'],
                'label' => $desired['label'],
                'xml_id' => $desired['xml_id'],
            ];
        }

        return [
            'prop_id' => $propId,
            'prop_code' => 'property'.$propId,
            'enums' => $resolvedEnums,
        ];
    }

    /**
     * @return array{prop_id: int, prop_code: string, enums: list<never>}
     */
    private function ensureScalarCatalogProperty(
        Tenant $tenant,
        int $iblockId,
        array &$existingProps,
        string $code,
        string $name,
        string $propertyType,
        array &$log,
    ): array {
        $matched = collect($existingProps)->first(function (array $p) use ($code, $name) {
            return strtoupper((string) ($p['code'] ?? '')) === strtoupper($code)
                || strtoupper((string) ($p['xmlId'] ?? '')) === strtoupper($code)
                || strcasecmp(trim((string) ($p['name'] ?? '')), $name) === 0;
        });

        if (! $matched) {
            $res = $this->client->call($tenant, 'catalog.productProperty.add', [
                'fields' => [
                    'iblockId' => $iblockId,
                    'name' => $name,
                    'code' => $code,
                    'xmlId' => $code,
                    'propertyType' => $propertyType,
                    'multiple' => 'N',
                    'active' => 'Y',
                    'sort' => 200,
                ],
            ]);
            $matched = $res['result']['productProperty'] ?? [];
            $existingProps[] = $matched;
            $log[] = "Created catalog attribute '{$name}' (property{$matched['id']}).";
        }

        $propId = (int) ($matched['id'] ?? 0);

        return [
            'prop_id' => $propId,
            'prop_code' => 'property'.$propId,
            'enums' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPortalTypes(Tenant $tenant): array
    {
        $res = $this->client->call($tenant, 'crm.type.list');

        return (array) ($res['result']['types'] ?? []);
    }

    /**
     * @return array{id: int, entityTypeId: int, title: string}
     */
    private function ensureFeeSpa(Tenant $tenant, array &$portalSpas, string $title, array &$log): array
    {
        $found = collect($portalSpas)->first(function (array $spa) use ($title) {
            return strcasecmp(trim((string) ($spa['title'] ?? '')), trim($title)) === 0;
        });

        if ($found) {
            return [
                'id' => (int) $found['id'],
                'entityTypeId' => (int) $found['entityTypeId'],
                'title' => (string) $found['title'],
            ];
        }

        $res = $this->client->call($tenant, 'crm.type.add', [
            'fields' => [
                'title' => $title,
                'isUseInUserfieldEnabled' => 'Y',
                'isLinkWithProductsEnabled' => 'Y',
                'isDocumentsEnabled' => 'Y',
                'isAutomationEnabled' => 'Y',
                'isStagesEnabled' => 'N',
                'isSetOpenPermissions' => 'Y',
            ],
        ]);

        $created = $res['result']['type'] ?? [];
        $portalSpas[] = $created;
        $log[] = "Created Smart Process '{$title}' (entityTypeId={$created['entityTypeId']}, id={$created['id']}).";

        return [
            'id' => (int) $created['id'],
            'entityTypeId' => (int) $created['entityTypeId'],
            'title' => (string) ($created['title'] ?? $title),
        ];
    }

    /**
     * @return array{field_map: array<string, string>, enum_rows: list<array<string, mixed>>}
     */
    private function provisionSpaFields(
        Tenant $tenant,
        int $spaInternalId,
        int $spaEntityTypeId,
        array $costTypesConfig,
        array $optionsConfig,
        array $paymentDefaults,
        array $normalizedExtras,
        array &$log,
    ): array {
        $existingFields = $this->listEntityFields($tenant, $spaEntityTypeId, $spaInternalId);

        $fieldMap = [
            'title' => 'title',
            'xml_id' => 'xmlId',
            'price' => 'opportunity',
        ];
        $enumRows = [];

        // Quantity field
        $qtyField = $this->ensureEntityField($tenant, $spaEntityTypeId, $spaInternalId, $existingFields, [
            'suffix' => 'INLEX_QTY',
            'xml_id' => 'INLEX_QTY',
            'label' => 'Quantity',
            'user_type_id' => 'double',
            'multiple' => 'N',
            'settings' => [],
        ], $log);
        $fieldMap['quantity'] = $qtyField['code'];

        // Cost Type enum field
        $costEnums = collect($costTypesConfig)->map(fn (array $ct, int $idx) => [
            'key' => $ct['key'],
            'label' => $ct['label'],
            'xml_id' => 'INLEX_COST_'.strtoupper($ct['key']),
            'sort' => ($idx + 1) * 100,
        ])->all();
        $costField = $this->ensureEntityField($tenant, $spaEntityTypeId, $spaInternalId, $existingFields, [
            'suffix' => 'INLEX_COST',
            'xml_id' => 'INLEX_COST_TYPE',
            'label' => 'Cost Type',
            'user_type_id' => 'enumeration',
            'multiple' => 'N',
            'settings' => [],
            'enum' => $costEnums,
        ], $log);
        $fieldMap['cost_type'] = $costField['code'];
        foreach ($costField['enums'] as $e) {
            $enumRows[] = [
                'mapping_group' => 'cost_type',
                'attr_key' => 'cost_type',
                'logical_key' => $e['key'],
                'logical_label' => $e['label'],
                'spa_field_code' => $costField['code'],
                'spa_enum_id' => $e['id'],
            ];
        }

        // Option Group enum field
        $optEnums = collect($optionsConfig)->map(fn (array $opt, int $idx) => [
            'key' => $opt['key'],
            'label' => $opt['label'],
            'xml_id' => 'INLEX_'.strtoupper($opt['key']),
            'sort' => ($idx + 1) * 100,
        ])->all();
        $optField = $this->ensureEntityField($tenant, $spaEntityTypeId, $spaInternalId, $existingFields, [
            'suffix' => 'INLEX_OPT',
            'xml_id' => 'INLEX_OPTION',
            'label' => 'Quote Option',
            'user_type_id' => 'enumeration',
            'multiple' => 'N',
            'settings' => [],
            'enum' => $optEnums,
        ], $log);
        $fieldMap['option_group'] = $optField['code'];
        foreach ($optField['enums'] as $e) {
            $enumRows[] = [
                'mapping_group' => 'option_group',
                'attr_key' => 'option_group',
                'logical_key' => $e['key'],
                'logical_label' => $e['label'],
                'spa_field_code' => $optField['code'],
                'spa_enum_id' => $e['id'],
            ];
        }

        // Payment Schedule enum field
        $payField = $this->ensureEntityField($tenant, $spaEntityTypeId, $spaInternalId, $existingFields, [
            'suffix' => 'INLEX_PAY',
            'xml_id' => 'INLEX_PAYMENTS',
            'label' => 'Payment Schedule',
            'user_type_id' => 'enumeration',
            'multiple' => 'N',
            'settings' => [],
            'enum' => $paymentDefaults,
        ], $log);
        $fieldMap['payment_schedule'] = $payField['code'];
        foreach ($payField['enums'] as $e) {
            $enumRows[] = [
                'mapping_group' => 'payment_schedule',
                'attr_key' => 'payment_schedule',
                'logical_key' => $e['key'],
                'logical_label' => $e['label'],
                'spa_field_code' => $payField['code'],
                'spa_enum_id' => $e['id'],
            ];
        }

        // Custom product attributes mirrored to SPA
        foreach ($normalizedExtras as $extra) {
            if (empty($extra['sync_to_spa'])) {
                continue;
            }
            $isList = ($extra['property_type'] ?? 'S') === 'L';
            $suffix = 'INLEX_A_'.strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $extra['key']), 0, 14));
            $spec = [
                'suffix' => $suffix,
                'xml_id' => 'INLEX_ATTR_'.strtoupper($extra['key']),
                'label' => $extra['label'],
                'user_type_id' => $isList ? 'enumeration' : 'string',
                'multiple' => 'N',
                'settings' => [],
            ];
            if ($isList) {
                $spec['enum'] = collect($extra['enum_options'])->map(fn (array $opt, int $idx) => [
                    'key' => $opt['key'],
                    'label' => $opt['label'],
                    'xml_id' => $opt['xml_id'],
                    'sort' => ($idx + 1) * 100,
                ])->all();
            }

            $extraField = $this->ensureEntityField($tenant, $spaEntityTypeId, $spaInternalId, $existingFields, $spec, $log);
            $fieldMap['attr:'.$extra['key']] = $extraField['code'];

            if ($isList) {
                foreach ($extraField['enums'] as $e) {
                    $enumRows[] = [
                        'mapping_group' => 'custom_attr',
                        'attr_key' => $extra['key'],
                        'logical_key' => $e['key'],
                        'logical_label' => $e['label'],
                        'spa_field_code' => $extraField['code'],
                        'spa_enum_id' => $e['id'],
                    ];
                }
            }
        }

        return [
            'field_map' => $fieldMap,
            'enum_rows' => $enumRows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listEntityFields(Tenant $tenant, int $entityTypeId, ?int $spaInternalId): array
    {
        if ($entityTypeId === 1) {
            $res = $this->client->call($tenant, 'crm.lead.fields');

            return $this->normalizeClassicFields((array) ($res['result'] ?? []));
        }

        if ($entityTypeId === 2) {
            $res = $this->client->call($tenant, 'crm.deal.fields');

            return $this->normalizeClassicFields((array) ($res['result'] ?? []));
        }

        $out = [];
        if ($spaInternalId !== null && $spaInternalId > 0) {
            $res = $this->client->call($tenant, 'userfieldconfig.list', [
                'moduleId' => 'crm',
                'filter' => ['entityId' => "CRM_{$spaInternalId}"],
            ]);
            foreach ((array) ($res['result']['fields'] ?? []) as $uf) {
                $out[] = [
                    'code' => (string) ($uf['fieldName'] ?? ''),
                    'xmlId' => (string) ($uf['xmlId'] ?? ''),
                    'label' => $this->adapter->extractLabel($uf, (string) ($uf['fieldName'] ?? '')),
                    'type' => (string) ($uf['userTypeId'] ?? 'string'),
                    'enum' => (array) ($uf['enum'] ?? []),
                ];
            }
        } elseif ($entityTypeId === 7) {
            $res = $this->client->call($tenant, 'crm.quote.userfield.list');
            foreach ((array) ($res['result'] ?? []) as $uf) {
                $out[] = [
                    'code' => (string) ($uf['FIELD_NAME'] ?? ''),
                    'xmlId' => (string) ($uf['XML_ID'] ?? ''),
                    'label' => $this->adapter->extractLabel($uf, (string) ($uf['FIELD_NAME'] ?? '')),
                    'type' => (string) ($uf['USER_TYPE_ID'] ?? 'string'),
                    'enum' => (array) ($uf['LIST'] ?? []),
                ];
            }
        }

        return $out;
    }

    private function normalizeClassicFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $code => $def) {
            if (! is_array($def) || ! Str::startsWith((string) $code, 'UF_CRM')) {
                continue;
            }
            $out[] = [
                'code' => (string) $code,
                'xmlId' => (string) ($def['xmlId'] ?? $def['XML_ID'] ?? ''),
                'label' => $this->adapter->extractLabel($def, (string) $code),
                'type' => (string) ($def['type'] ?? 'string'),
                'enum' => (array) ($def['items'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{code: string, enums: list<array{id: string, key: string, label: string}>}
     */
    private function ensureEntityField(
        Tenant $tenant,
        int $entityTypeId,
        ?int $spaInternalId,
        array &$existingFields,
        array $spec,
        array &$log,
    ): array {
        $suffix = strtoupper((string) $spec['suffix']);
        $xmlId = (string) ($spec['xml_id'] ?? $suffix);
        $label = (string) $spec['label'];
        $userTypeId = (string) $spec['user_type_id'];
        $multiple = (string) ($spec['multiple'] ?? 'N');
        $settings = (array) ($spec['settings'] ?? []);
        $enumSpecs = (array) ($spec['enum'] ?? []);

        $expectedCode = $spaInternalId !== null
            ? "UF_CRM_{$spaInternalId}_{$suffix}"
            : "UF_CRM_{$suffix}";

        $matched = collect($existingFields)->first(function (array $f) use ($expectedCode, $xmlId, $label) {
            return strtoupper((string) ($f['code'] ?? '')) === strtoupper($expectedCode)
                || ($xmlId !== '' && strtoupper((string) ($f['xmlId'] ?? '')) === strtoupper($xmlId))
                || strcasecmp(trim((string) ($f['label'] ?? '')), trim($label)) === 0;
        });

        if ($matched) {
            return [
                'code' => (string) $matched['code'],
                'enums' => $this->matchExistingEnumItems((array) ($matched['enum'] ?? []), $enumSpecs),
            ];
        }

        if ($spaInternalId !== null) {
            $payload = [
                'moduleId' => 'crm',
                'field' => [
                    'entityId' => "CRM_{$spaInternalId}",
                    'fieldName' => substr($expectedCode, 0, 50),
                    'userTypeId' => $userTypeId,
                    'xmlId' => $xmlId,
                    'multiple' => $multiple,
                    'editFormLabel' => ['en' => $label],
                    'listColumnLabel' => ['en' => $label],
                    'settings' => $settings,
                ],
            ];
            if ($userTypeId === 'enumeration' && $enumSpecs !== []) {
                $payload['field']['enum'] = collect($enumSpecs)->map(fn (array $e) => [
                    'value' => $e['label'],
                    'xmlId' => $e['xml_id'],
                    'sort' => $e['sort'],
                    'def' => 'N',
                ])->values()->all();
            }

            $res = $this->client->call($tenant, 'userfieldconfig.add', $payload);
            $created = $res['result']['field'] ?? [];
            $fieldCode = (string) ($created['fieldName'] ?? substr($expectedCode, 0, 50));
            $existingFields[] = [
                'code' => $fieldCode,
                'xmlId' => $xmlId,
                'label' => $label,
                'type' => $userTypeId,
                'enum' => (array) ($created['enum'] ?? []),
            ];
            $log[] = "Created SPA field '{$label}' ({$fieldCode}).";

            return [
                'code' => $fieldCode,
                'enums' => $this->matchExistingEnumItems((array) ($created['enum'] ?? []), $enumSpecs),
            ];
        }

        // Classic CRM Entity userfield creation (Lead=1, Deal=2, Quote=7)
        $method = match ($entityTypeId) {
            1 => 'crm.lead.userfield.add',
            2 => 'crm.deal.userfield.add',
            7 => 'crm.quote.userfield.add',
            default => throw new BitrixApiException('UNSUPPORTED_ENTITY', "Unsupported entityTypeId {$entityTypeId}", 422),
        };

        $fieldsPayload = [
            'FIELD_NAME' => substr($suffix, 0, 43),
            'USER_TYPE_ID' => $userTypeId,
            'XML_ID' => $xmlId,
            'LABEL' => $label,
            'EDIT_FORM_LABEL' => ['en' => $label],
            'LIST_COLUMN_LABEL' => ['en' => $label],
            'MULTIPLE' => $multiple,
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'SETTINGS' => $settings,
        ];

        $this->client->call($tenant, $method, ['fields' => $fieldsPayload]);
        $createdCode = 'UF_CRM_'.substr($suffix, 0, 43);
        $existingFields[] = [
            'code' => $createdCode,
            'xmlId' => $xmlId,
            'label' => $label,
            'type' => $userTypeId,
            'enum' => [],
        ];
        $log[] = "Created CRM entity({$entityTypeId}) field '{$label}' ({$createdCode}).";

        return [
            'code' => $createdCode,
            'enums' => [],
        ];
    }

    /**
     * @return list<array{id: string, key: string, label: string}>
     */
    private function matchExistingEnumItems(array $rawItems, array $enumSpecs): array
    {
        if ($enumSpecs === [] || $rawItems === []) {
            return [];
        }

        $out = [];
        foreach ($enumSpecs as $spec) {
            $match = collect($rawItems)->first(function (array $item) use ($spec) {
                $val = (string) ($item['value'] ?? $item['VALUE'] ?? '');
                $xml = (string) ($item['xmlId'] ?? $item['XML_ID'] ?? '');

                return ($xml !== '' && strcasecmp($xml, $spec['xml_id']) === 0)
                    || strcasecmp(trim($val), trim($spec['label'])) === 0;
            });

            if ($match) {
                $out[] = [
                    'id' => (string) ($match['id'] ?? $match['ID'] ?? ''),
                    'key' => $spec['key'],
                    'label' => $spec['label'],
                ];
            }
        }

        return $out;
    }

    private function resolveTargetEntityMeta(int $entityTypeId, array $portalSpas): ?array
    {
        return match ($entityTypeId) {
            1 => ['logical_key' => 'lead', 'title' => 'Leads', 'spa_id' => null],
            2 => ['logical_key' => 'deal', 'title' => 'Deals', 'spa_id' => null],
            7 => ['logical_key' => 'quote', 'title' => 'Estimates / Quotes', 'spa_id' => null],
            default => (function () use ($entityTypeId, $portalSpas) {
                $spa = collect($portalSpas)->first(fn (array $s) => (int) ($s['entityTypeId'] ?? 0) === $entityTypeId);
                if (! $spa) {
                    return null;
                }

                return [
                    'logical_key' => "spa_{$entityTypeId}",
                    'title' => (string) ($spa['title'] ?? "SPA {$entityTypeId}"),
                    'spa_id' => (int) $spa['id'],
                ];
            })(),
        };
    }

    private function defaultLogicalEntityKey(int $entityTypeId): string
    {
        return match ($entityTypeId) {
            1 => 'lead',
            2 => 'deal',
            7 => 'quote',
            default => "spa_{$entityTypeId}",
        };
    }

    private function bucketKey(string $costTypeKey, string $optionKey): string
    {
        $shortCost = match ($costTypeKey) {
            'professional' => 'prof',
            'government' => 'gov',
            default => substr(Str::snake($costTypeKey), 0, 8),
        };
        $shortOpt = match ($optionKey) {
            'option_1' => 'opt1',
            'option_2' => 'opt2',
            'option_3' => 'opt3',
            'option_4' => 'opt4',
            'option_5' => 'opt5',
            'option_6' => 'opt6',
            default => str_replace('option_', 'opt', Str::snake($optionKey)),
        };

        return "{$shortCost}_{$shortOpt}";
    }

    private function computeBucketCombinations(array $optionsConfig, array $costTypesConfig): array
    {
        $combos = [];
        foreach ($optionsConfig as $opt) {
            if (empty($opt['is_enabled'])) {
                continue;
            }
            foreach ($costTypesConfig as $ct) {
                if (empty($ct['is_enabled'])) {
                    continue;
                }
                $combos[] = [
                    'bucket_key' => $this->bucketKey($ct['key'], $opt['key']),
                    'title' => "{$ct['label']} - {$opt['label']}",
                    'cost_type_key' => $ct['key'],
                    'option_key' => $opt['key'],
                ];
            }
        }

        return $combos;
    }

    private function normalizeOptionsConfig(array $items, int $maxOptions): array
    {
        $out = [];
        foreach (array_values($items) as $idx => $item) {
            if (count($out) >= $maxOptions) {
                break;
            }
            $label = trim((string) ($item['label'] ?? ('Option '.($idx + 1))));
            $key = trim((string) ($item['key'] ?? ('option_'.($idx + 1))));
            $out[] = [
                'key' => Str::snake($key),
                'label' => $label,
                'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
                'is_enabled' => (bool) ($item['is_enabled'] ?? true),
            ];
        }

        return $out ?: TenantCatalogConfig::defaultOptionsConfig();
    }

    private function normalizeCostTypesConfig(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $idx => $item) {
            $label = trim((string) ($item['label'] ?? ('Cost Type '.($idx + 1))));
            $key = trim((string) ($item['key'] ?? Str::snake($label)));
            $out[] = [
                'key' => Str::snake($key),
                'label' => $label,
                'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
                'is_enabled' => (bool) ($item['is_enabled'] ?? true),
            ];
        }

        return $out ?: TenantCatalogConfig::defaultCostTypesConfig();
    }

    private function normalizeMetaSchema(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $idx => $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $key = trim((string) ($item['key'] ?? Str::snake($label)));
            if ($key === '' || $label === '') {
                continue;
            }
            $out[] = [
                'key' => Str::snake($key),
                'label' => $label,
                'field_type' => (string) ($item['field_type'] ?? 'string'),
                'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
            ];
        }

        return $out;
    }
}
