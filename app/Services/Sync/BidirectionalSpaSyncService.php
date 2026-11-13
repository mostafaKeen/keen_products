<?php

namespace App\Services\Sync;

use App\Exceptions\BitrixApiException;
use App\Models\SyncedSpaLink;
use App\Models\Tenant;
use App\Models\TenantEntityConfig;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixFieldAdapter;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Support\Facades\Cache;

class BidirectionalSpaSyncService
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixFieldAdapter $adapter,
        private readonly SubscriptionGate $gate,
        private readonly TenantSyncService $syncService,
    ) {}

    /**
     * Propagate price / quantity edits from a child Fee SPA item back to its parent CRM entity product row.
     *
     * @return array<string, mixed>
     */
    public function handleSpaItemUpdated(Tenant $tenant, int $spaEntityTypeId, int $spaItemId): array
    {
        if (Cache::has(TenantSyncService::spaSelfWriteCacheKey($tenant->id, $spaEntityTypeId, $spaItemId))) {
            return ['success' => true, 'skipped' => true, 'reason' => 'self_write_suppressed'];
        }

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: true);
        if (! $subEval['allowed'] || empty($subEval['features']['allow_bidirectional_spa_sync'])) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Bidirectional SPA sync not enabled on current plan.'];
        }

        $bucket = $tenant->spaBuckets()
            ->where('spa_entity_type_id', $spaEntityTypeId)
            ->where('is_active', true)
            ->first();

        if (! $bucket) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Not a configured Fee SPA bucket.'];
        }

        // O(1) parent entity resolution via DB link table
        $link = SyncedSpaLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('spa_entity_type_id', $spaEntityTypeId)
            ->where('spa_item_id', $spaItemId)
            ->first();

        try {
            $res = $this->client->call($tenant, 'crm.item.get', [
                'entityTypeId' => $spaEntityTypeId,
                'id' => $spaItemId,
                'useOriginalUfNames' => 'Y',
            ]);
            $spaItem = $res['result']['item'] ?? null;
        } catch (BitrixApiException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (! is_array($spaItem)) {
            return ['success' => false, 'error' => 'SPA item not found on portal.'];
        }

        $parentEntityTypeId = $link?->parent_entity_type_id;
        $parentEntityId = $link?->parent_entity_id;

        if (! $parentEntityTypeId || ! $parentEntityId) {
            $xmlId = (string) ($spaItem['xmlId'] ?? '');
            if (preg_match('/^(\d+)_(\d+)_(?:p|r)(\d+)$/', $xmlId, $m)) {
                $parentEntityTypeId = (int) $m[1];
                $parentEntityId = (int) $m[2];
            }
        }

        if (! $parentEntityTypeId || ! $parentEntityId) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Could not resolve parent entity for SPA item.'];
        }

        $entityConfig = $tenant->entityConfigs()
            ->where('entity_type_id', $parentEntityTypeId)
            ->first();
        $ownerType = $entityConfig?->owner_type_code ?: TenantEntityConfig::ownerCodeForEntityTypeId($parentEntityTypeId);

        $rowsRes = $this->client->call($tenant, 'crm.item.productrow.list', [
            'filter' => ['=ownerType' => $ownerType, '=ownerId' => $parentEntityId],
        ]);
        $productRows = (array) ($rowsRes['result']['productRows'] ?? []);

        $targetRow = collect($productRows)->first(function (array $row) use ($link, $spaItem) {
            if ($link?->product_row_id && (int) ($row['id'] ?? 0) === $link->product_row_id) {
                return true;
            }
            if ($link?->product_id && (int) ($row['productId'] ?? 0) === $link->product_id) {
                return true;
            }

            return strcasecmp(trim((string) ($row['productName'] ?? '')), trim((string) ($spaItem['title'] ?? ''))) === 0;
        });

        if (! $targetRow || empty($targetRow['id'])) {
            return ['success' => false, 'error' => 'Matching product row not found on parent entity.'];
        }

        $priceField = $bucket->resolveFieldCode('price', 'opportunity');
        $qtyField = $bucket->resolveFieldCode('quantity');

        $amount = (float) $this->adapter->readFieldValue($spaItem, $priceField, 0);
        $quantity = $qtyField ? (float) $this->adapter->readFieldValue($spaItem, $qtyField, 1) : (float) ($targetRow['quantity'] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1.0;
        }
        $unitPrice = round($amount / $quantity, 4);

        Cache::put(TenantSyncService::selfWriteCacheKey($tenant->id, $parentEntityTypeId, $parentEntityId), true, 15);

        $this->client->call($tenant, 'crm.item.productrow.update', [
            'id' => (int) $targetRow['id'],
            'fields' => [
                'productName' => (string) ($spaItem['title'] ?? $targetRow['productName']),
                'price' => $unitPrice,
                'quantity' => $quantity,
            ],
        ]);

        // Recompute parent entity subtotals and Totals
        return $this->syncService->syncEntity($tenant, $parentEntityTypeId, $parentEntityId, 'spa_reverse', null, true);
    }
}
