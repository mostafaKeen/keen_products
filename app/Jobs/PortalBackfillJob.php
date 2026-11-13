<?php

namespace App\Jobs;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\TenantSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PortalBackfillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $entityTypeId,
        public readonly int $limit = 100,
    ) {}

    public function handle(
        Bitrix24Client $client,
        TenantSyncService $syncService,
        SubscriptionGate $gate,
    ): void {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || ! $tenant->isActive()) {
            return;
        }

        $subEval = $gate->evaluate($tenant, requireSyncQuota: true);
        if (! $subEval['allowed'] || empty($subEval['features']['allow_backfill'])) {
            return;
        }

        $ids = [];
        try {
            if ($this->entityTypeId === 1) {
                $res = $client->call($tenant, 'crm.lead.list', [
                    'order' => ['ID' => 'DESC'],
                    'select' => ['ID'],
                ]);
                foreach ((array) ($res['result'] ?? []) as $item) {
                    if (! empty($item['ID'])) {
                        $ids[] = (int) $item['ID'];
                    }
                }
            } elseif ($this->entityTypeId === 2) {
                $res = $client->call($tenant, 'crm.deal.list', [
                    'order' => ['ID' => 'DESC'],
                    'select' => ['ID'],
                ]);
                foreach ((array) ($res['result'] ?? []) as $item) {
                    if (! empty($item['ID'])) {
                        $ids[] = (int) $item['ID'];
                    }
                }
            } else {
                $res = $client->call($tenant, 'crm.item.list', [
                    'entityTypeId' => $this->entityTypeId,
                    'order' => ['id' => 'DESC'],
                    'select' => ['id'],
                ]);
                foreach ((array) ($res['result']['items'] ?? []) as $item) {
                    if (! empty($item['id'])) {
                        $ids[] = (int) $item['id'];
                    }
                }
            }

            foreach (array_slice($ids, 0, $this->limit) as $entityId) {
                $subCheck = $gate->evaluate($tenant, requireSyncQuota: true);
                if (! $subCheck['allowed']) {
                    break;
                }
                $syncService->syncEntity($tenant, $this->entityTypeId, $entityId, 'backfill', null, false);
            }
        } catch (BitrixApiException $e) {
            if ($e->isOperatingLimit() || $e->isRateLimited()) {
                $this->release($e->retryAfterSeconds());

                return;
            }
            throw $e;
        }
    }
}
