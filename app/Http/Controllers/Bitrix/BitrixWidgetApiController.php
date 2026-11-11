<?php

namespace App\Http\Controllers\Bitrix;

use App\Exceptions\BitrixApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixFieldAdapter;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\TenantSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BitrixWidgetApiController extends Controller
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixFieldAdapter $adapter,
        private readonly SubscriptionGate $gate,
        private readonly TenantSyncService $syncService,
    ) {}

    public function load(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $claims = (array) $request->attributes->get('bitrix_claims', []);

        $entityTypeId = (int) ($claims['entity_type_id'] ?? $request->input('entity_type_id', 0));
        $entityId = (int) ($claims['entity_id'] ?? $request->input('entity_id', 0));

        if ($entityTypeId <= 0 || $entityId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid CRM entity context.'], 422);
        }

        try {
            $context = $this->syncService->loadWidgetContext($tenant, $entityTypeId, $entityId);

            return response()->json(['success' => true, 'context' => $context]);
        } catch (BitrixApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function catalogSearch(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $catalogConfig = $tenant->catalogConfig;

        if (! $catalogConfig || ! $catalogConfig->iblock_id) {
            return response()->json(['success' => false, 'message' => 'Commercial Catalog is not configured.'], 422);
        }

        $q = trim((string) $request->input('q', ''));
        $filter = ['iblockId' => $catalogConfig->iblock_id];
        if ($q !== '') {
            $filter['%name'] = $q;
        }

        try {
            $prodRes = $this->client->call($tenant, 'catalog.product.list', [
                'select' => ['id', 'iblockId', 'name', 'vatId', 'vatIncluded', 'property*'],
                'filter' => $filter,
                'order' => ['id' => 'DESC'],
            ]);

            $products = (array) ($prodRes['result']['products'] ?? []);
            $productIds = collect($products)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

            $pricesByProduct = [];
            if ($productIds !== []) {
                $priceRes = $this->client->call($tenant, 'catalog.price.list', [
                    'select' => ['id', 'productId', 'catalogGroupId', 'price', 'currency'],
                    'filter' => ['productId' => $productIds],
                ]);
                foreach ((array) ($priceRes['result']['prices'] ?? []) as $price) {
                    $pid = (int) ($price['productId'] ?? 0);
                    if ($pid > 0 && ! isset($pricesByProduct[$pid])) {
                        $pricesByProduct[$pid] = [
                            'price_id' => (int) $price['id'],
                            'price' => (float) ($price['price'] ?? 0),
                            'currency' => (string) ($price['currency'] ?? 'USD'),
                        ];
                    }
                }
            }

            $items = [];
            foreach ($products as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $costVal = $this->adapter->extractCatalogPropertyValue($p, $catalogConfig->cost_type_prop_code);
                $optVal = $this->adapter->extractCatalogPropertyValue($p, $catalogConfig->option_prop_code);
                $payVal = $this->adapter->extractCatalogPropertyValue($p, $catalogConfig->payments_prop_code);

                $extraValues = [];
                foreach ((array) ($catalogConfig->extra_attributes_json ?? []) as $attr) {
                    if (! empty($attr['prop_code'])) {
                        $extraValues[$attr['key']] = (string) ($this->adapter->extractCatalogPropertyValue($p, $attr['prop_code']) ?? '');
                    }
                }

                $items[] = [
                    'id' => $pid,
                    'name' => (string) ($p['name'] ?? ''),
                    'price' => $pricesByProduct[$pid]['price'] ?? 0.0,
                    'currency' => $pricesByProduct[$pid]['currency'] ?? 'USD',
                    'price_id' => $pricesByProduct[$pid]['price_id'] ?? null,
                    'vatIncluded' => ($p['vatIncluded'] ?? 'N') === 'Y',
                    'costTypeKey' => $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'cost_type', 'cost_type', $costVal),
                    'optionKey' => $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'option_group', 'option_group', $optVal),
                    'paymentKey' => $this->adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'payment_schedule', 'payment_schedule', $payVal),
                    'extraAttributes' => $extraValues,
                ];
            }

            return response()->json([
                'success' => true,
                'products' => $items,
            ]);
        } catch (BitrixApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function upsertCatalogProduct(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $catalogConfig = $tenant->catalogConfig;

        if (! $catalogConfig || ! $catalogConfig->iblock_id) {
            return response()->json(['success' => false, 'message' => 'Commercial Catalog is not configured.'], 422);
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'costTypeKey' => ['nullable', 'string'],
            'optionKey' => ['nullable', 'string'],
            'paymentKey' => ['nullable', 'string'],
            'extraAttributes' => ['nullable', 'array'],
        ]);

        try {
            $productId = ! empty($validated['id']) ? (int) $validated['id'] : null;
            $existingProd = null;
            if ($productId) {
                $getRes = $this->client->call($tenant, 'catalog.product.get', ['id' => $productId]);
                $existingProd = $getRes['result']['product'] ?? null;
            }

            $fields = [
                'name' => $validated['name'],
                'active' => 'Y',
            ];
            if (! $productId) {
                $fields['iblockId'] = $catalogConfig->iblock_id;
            }

            $assignProp = function (?string $propCode, ?string $enumId) use (&$fields, $productId, $existingProd) {
                if (! $propCode || $enumId === null || $enumId === '') {
                    return;
                }
                if ($productId) {
                    $vid = $this->adapter->extractCatalogPropertyValueId($existingProd, $propCode);
                    $fields[$propCode] = $vid ? ['value' => $enumId, 'valueId' => $vid] : ['value' => $enumId];
                } else {
                    $fields[$propCode] = $enumId;
                }
            };

            if (! empty($validated['costTypeKey'])) {
                $assignProp(
                    $catalogConfig->cost_type_prop_code,
                    $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'cost_type', 'cost_type', $validated['costTypeKey'])
                );
            }
            if (! empty($validated['optionKey'])) {
                $assignProp(
                    $catalogConfig->option_prop_code,
                    $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'option_group', 'option_group', $validated['optionKey'])
                );
            }
            if (! empty($validated['paymentKey'])) {
                $assignProp(
                    $catalogConfig->payments_prop_code,
                    $this->adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'payment_schedule', 'payment_schedule', $validated['paymentKey'])
                );
            }

            foreach ((array) ($catalogConfig->extra_attributes_json ?? []) as $attr) {
                $key = $attr['key'] ?? null;
                $code = $attr['prop_code'] ?? null;
                if ($key && $code && isset($validated['extraAttributes'][$key])) {
                    $assignProp($code, (string) $validated['extraAttributes'][$key]);
                }
            }

            if ($productId) {
                $this->client->call($tenant, 'catalog.product.update', ['id' => $productId, 'fields' => $fields]);
            } else {
                $addRes = $this->client->call($tenant, 'catalog.product.add', ['fields' => $fields]);
                $productId = (int) ($addRes['result']['element']['id'] ?? $addRes['result']['product']['id'] ?? 0);
            }

            // Upsert base price in Commercial Catalog
            if ($productId > 0) {
                $existingPricesRes = $this->client->call($tenant, 'catalog.price.list', [
                    'select' => ['id', 'catalogGroupId'],
                    'filter' => ['productId' => $productId],
                ]);
                $existingPrice = $existingPricesRes['result']['prices'][0] ?? null;

                if ($existingPrice && ! empty($existingPrice['id'])) {
                    $this->client->call($tenant, 'catalog.price.update', [
                        'id' => (int) $existingPrice['id'],
                        'fields' => [
                            'price' => (float) $validated['price'],
                            'currency' => strtoupper($validated['currency']),
                        ],
                    ]);
                } else {
                    $groupId = 1;
                    try {
                        $typesRes = $this->client->call($tenant, 'catalog.priceType.list');
                        $baseType = collect($typesRes['result']['priceTypes'] ?? [])->firstWhere('base', 'Y');
                        if ($baseType && ! empty($baseType['id'])) {
                            $groupId = (int) $baseType['id'];
                        }
                    } catch (BitrixApiException) {
                    }

                    $this->client->call($tenant, 'catalog.price.add', [
                        'fields' => [
                            'productId' => $productId,
                            'catalogGroupId' => $groupId,
                            'price' => (float) $validated['price'],
                            'currency' => strtoupper($validated['currency']),
                        ],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'product' => [
                    'id' => $productId,
                    'name' => $validated['name'],
                    'price' => (float) $validated['price'],
                    'currency' => strtoupper($validated['currency']),
                    'costTypeKey' => $validated['costTypeKey'] ?? null,
                    'optionKey' => $validated['optionKey'] ?? null,
                    'paymentKey' => $validated['paymentKey'] ?? null,
                    'extraAttributes' => $validated['extraAttributes'] ?? [],
                ],
            ]);
        } catch (BitrixApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function sync(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $claims = (array) $request->attributes->get('bitrix_claims', []);

        $entityTypeId = (int) ($claims['entity_type_id'] ?? $request->input('entity_type_id', 0));
        $entityId = (int) ($claims['entity_id'] ?? $request->input('entity_id', 0));

        if ($entityTypeId <= 0 || $entityId <= 0) {
            return response()->json(['success' => false, 'message' => 'Missing CRM entity context.'], 422);
        }

        $validated = $request->validate([
            'currencyId' => ['nullable', 'string', 'max:8'],
            'rows' => ['present', 'array'],
            'rows.*.rowId' => ['nullable', 'integer'],
            'rows.*.productId' => ['nullable', 'integer'],
            'rows.*.productName' => ['required', 'string', 'max:500'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'rows.*.taxRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.taxIncluded' => ['nullable', 'boolean'],
            'rows.*.sort' => ['nullable', 'integer'],
            'rows.*.costTypeKey' => ['nullable', 'string'],
            'rows.*.optionKey' => ['nullable', 'string'],
            'rows.*.paymentKey' => ['nullable', 'string'],
            'rows.*.extraAttributes' => ['nullable', 'array'],
            'optionMeta' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->syncService->syncEntity($tenant, $entityTypeId, $entityId, 'widget', $validated, true);
            $context = $this->syncService->loadWidgetContext($tenant, $entityTypeId, $entityId);

            return response()->json([
                'success' => $result['success'],
                'result' => $result,
                'context' => $context,
            ], $result['success'] ? 200 : 422);
        } catch (BitrixApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function clear(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('bitrix_tenant');
        $claims = (array) $request->attributes->get('bitrix_claims', []);

        $entityTypeId = (int) ($claims['entity_type_id'] ?? $request->input('entity_type_id', 0));
        $entityId = (int) ($claims['entity_id'] ?? $request->input('entity_id', 0));

        if ($entityTypeId <= 0 || $entityId <= 0) {
            return response()->json(['success' => false, 'message' => 'Missing CRM entity context.'], 422);
        }

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: true);
        if (! $subEval['allowed']) {
            return response()->json(['success' => false, 'message' => $subEval['reason']], 403);
        }

        try {
            $result = $this->syncService->syncEntity($tenant, $entityTypeId, $entityId, 'widget', [
                'currencyId' => $request->input('currencyId'),
                'rows' => [],
                'optionMeta' => (array) $request->input('optionMeta', []),
            ], true);
            $context = $this->syncService->loadWidgetContext($tenant, $entityTypeId, $entityId);

            return response()->json([
                'success' => $result['success'],
                'result' => $result,
                'context' => $context,
            ], $result['success'] ? 200 : 422);
        } catch (BitrixApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}

