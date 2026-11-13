<?php

namespace App\Jobs;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use App\Models\TenantEntityConfig;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\BidirectionalSpaSyncService;
use App\Services\Sync\TenantSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessBitrixEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $uniqueFor = 10;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $eventType,
        public readonly int $entityTypeId,
        public readonly int $entityId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->eventType}:{$this->entityTypeId}:{$this->entityId}";
    }

    public function handle(
        TenantSyncService $syncService,
        BidirectionalSpaSyncService $reverseSyncService,
        Bitrix24Client $client,
        SubscriptionGate $gate,
    ): void {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || ! $tenant->isActive()) {
            return;
        }

        $subEval = $gate->evaluate($tenant, requireSyncQuota: true);
        if (! $subEval['allowed'] || empty($subEval['features']['allow_auto_background_sync'])) {
            return;
        }

        try {
            if ($this->eventType === 'product_update') {
                $res = $client->call($tenant, 'crm.item.productrow.list', [
                    'filter' => ['=productId' => $this->entityId],
                ]);
                $rows = (array) ($res['result']['productRows'] ?? []);

                $enabledByOwner = $tenant->entityConfigs()
                    ->where('is_enabled', true)
                    ->where('auto_sync_enabled', true)
                    ->get()
                    ->keyBy('owner_type_code');

                $seen = [];
                foreach ($rows as $row) {
                    $ownerType = (string) ($row['ownerType'] ?? '');
                    $ownerId = (int) ($row['ownerId'] ?? 0);
                    /** @var ?TenantEntityConfig $ec */
                    $ec = $enabledByOwner->get($ownerType);
                    if (! $ec || $ownerId <= 0) {
                        continue;
                    }
                    $key = "{$ec->entity_type_id}:{$ownerId}";
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $syncService->syncEntity($tenant, $ec->entity_type_id, $ownerId, 'webhook');
                }

                return;
            }

            if ($this->eventType === 'dynamic_update') {
                $isFeeBucket = $tenant->spaBuckets()
                    ->where('spa_entity_type_id', $this->entityTypeId)
                    ->where('is_active', true)
                    ->exists();

                if ($isFeeBucket) {
                    $reverseSyncService->handleSpaItemUpdated($tenant, $this->entityTypeId, $this->entityId);

                    return;
                }
            }

            // Standard entity add/update (Lead = 1, Deal = 2, Quote = 7, Custom Target SPA)
            if (Cache::has(TenantSyncService::selfWriteCacheKey($tenant->id, $this->entityTypeId, $this->entityId))) {
                return;
            }

            // Coalesce rapid bursts of concurrent webhooks on the same entity
            $debounceKey = "b24_sync_debounce:{$this->tenantId}:{$this->entityTypeId}:{$this->entityId}";
            if (! Cache::add($debounceKey, true, 3)) {
                return;
            }

            try {
                $config = $tenant->entityConfigs()
                    ->where('entity_type_id', $this->entityTypeId)
                    ->where('is_enabled', true)
                    ->where('auto_sync_enabled', true)
                    ->first();

                if (! $config) {
                    return;
                }

                $syncService->syncEntity($tenant, $this->entityTypeId, $this->entityId, 'webhook');
            } finally {
                Cache::forget($debounceKey);
            }
        } catch (BitrixApiException $e) {
            if ($e->isOperatingLimit() || $e->isRateLimited()) {
                $this->release($e->retryAfterSeconds());

                return;
            }
            Log::warning('ProcessBitrixEventJob BitrixApiException', [
                'tenant_id' => $this->tenantId,
                'event' => $this->eventType,
                'entity_type_id' => $this->entityTypeId,
                'entity_id' => $this->entityId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
