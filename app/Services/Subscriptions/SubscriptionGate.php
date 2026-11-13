<?php

namespace App\Services\Subscriptions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUsageMonthly;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class SubscriptionGate
{
    public function ensureInitialSubscription(Tenant $tenant): Subscription
    {
        $existing = $tenant->subscriptions()->with('plan')->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $trialPlan = Plan::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN code = 'trial' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->first();

        if (! $trialPlan) {
            $trialPlan = Plan::create([
                'code' => 'trial',
                'name' => 'Free Trial',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'trial_days' => (int) config('services.bitrix24.default_trial_days', 14),
                'max_monthly_syncs' => 150,
                'max_options' => 2,
                'features_json' => [
                    'allow_auto_background_sync' => true,
                    'allow_bidirectional_spa_sync' => false,
                    'allow_custom_spas' => true,
                    'allow_custom_product_attributes' => true,
                    'allow_backfill' => false,
                ],
            ]);
        }

        $trialDays = max(1, (int) ($trialPlan->trial_days ?: config('services.bitrix24.default_trial_days', 14)));
        $now = now();

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $trialPlan->id,
            'status' => Subscription::STATUS_TRIALING,
            'billing_cycle' => 'monthly',
            'billing_provider' => 'manual',
            'trial_ends_at' => $now->copy()->addDays($trialDays),
            'current_period_start' => $now,
            'current_period_end' => $now->copy()->addDays($trialDays),
        ]);
    }

    /**
     * Evaluate portal access, expiration transitions, plan features, and monthly quota.
     *
     * @return array{
     *   allowed: bool,
     *   reason: ?string,
     *   banner: ?string,
     *   status: string,
     *   subscription: Subscription,
     *   plan: ?Plan,
     *   usage: TenantUsageMonthly,
     *   max_monthly_syncs: ?int,
     *   remaining_syncs: ?int,
     *   max_options: int,
     *   features: array<string, bool>
     * }
     */
    public function evaluate(Tenant $tenant, bool $requireSyncQuota = true): array
    {
        $subscription = $this->ensureInitialSubscription($tenant);
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        $subscription = $this->syncExpirationState($subscription);
        $usage = $this->currentUsage($tenant);

        $maxSyncs = $subscription->effectiveMaxMonthlySyncs();
        $remainingSyncs = $maxSyncs !== null ? max(0, $maxSyncs - $usage->sync_count) : null;
        $maxOptions = $subscription->effectiveMaxOptions();

        $features = [
            'allow_auto_background_sync' => $subscription->effectiveFeature('allow_auto_background_sync', true),
            'allow_bidirectional_spa_sync' => $subscription->effectiveFeature('allow_bidirectional_spa_sync', false),
            'allow_custom_spas' => $subscription->effectiveFeature('allow_custom_spas', true),
            'allow_custom_product_attributes' => $subscription->effectiveFeature('allow_custom_product_attributes', true),
            'allow_backfill' => $subscription->effectiveFeature('allow_backfill', false),
        ];

        $allowed = true;
        $reason = null;
        $banner = null;

        if ($tenant->install_status === Tenant::INSTALL_SUSPENDED) {
            $allowed = false;
            $reason = 'This Bitrix24 portal account is suspended. Please contact app support.';
        } elseif ($tenant->install_status === Tenant::INSTALL_UNINSTALLED) {
            $allowed = false;
            $reason = 'The application has been uninstalled from this Bitrix24 portal.';
        } elseif (in_array($subscription->status, [
            Subscription::STATUS_EXPIRED,
            Subscription::STATUS_SUSPENDED,
            Subscription::STATUS_CANCELED,
            Subscription::STATUS_PAST_DUE,
        ], true)) {
            $allowed = false;
            $reason = match ($subscription->status) {
                Subscription::STATUS_SUSPENDED => 'Subscription is suspended. Contact support or renew your plan.',
                Subscription::STATUS_PAST_DUE => 'Subscription payment is past due. Please renew in App Settings.',
                Subscription::STATUS_CANCELED => 'Subscription was canceled. Choose a plan in App Settings to resume syncing.',
                default => 'Your trial or billing period has expired. Please upgrade your subscription in App Settings.',
            };
        } elseif ($subscription->status === Subscription::STATUS_GRACE_PERIOD) {
            $daysLeft = $subscription->grace_ends_at
                ? max(0, (int) ceil(now()->diffInHours($subscription->grace_ends_at, false) / 24))
                : 0;
            $banner = "Grace period active ({$daysLeft} day(s) left). Renew soon to avoid sync interruption.";
        } elseif ($subscription->status === Subscription::STATUS_TRIALING && $subscription->trial_ends_at) {
            $daysLeft = max(0, (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24));
            if ($daysLeft <= 5) {
                $banner = "Free trial ends in {$daysLeft} day(s). Contact admin or upgrade from App Settings.";
            }
        }

        if ($allowed && $requireSyncQuota && $maxSyncs !== null && $usage->sync_count >= $maxSyncs) {
            $allowed = false;
            $reason = "Monthly sync quota reached ({$usage->sync_count}/{$maxSyncs}). Upgrade your plan to continue syncing.";
        }

        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'banner' => $banner,
            'status' => $subscription->status,
            'subscription' => $subscription,
            'plan' => $plan,
            'usage' => $usage,
            'max_monthly_syncs' => $maxSyncs,
            'remaining_syncs' => $remainingSyncs,
            'max_options' => $maxOptions,
            'features' => $features,
        ];
    }

    public function syncExpirationState(Subscription $subscription): Subscription
    {
        $now = Carbon::now();
        $graceDays = max(0, (int) config('services.bitrix24.grace_period_days', 7));

        if ($subscription->status === Subscription::STATUS_TRIALING && $subscription->trial_ends_at?->isPast()) {
            $graceEnds = $graceDays > 0
                ? $subscription->trial_ends_at->copy()->addDays($graceDays)
                : $subscription->trial_ends_at->copy();

            $subscription->status = $graceEnds->isFuture()
                ? Subscription::STATUS_GRACE_PERIOD
                : Subscription::STATUS_EXPIRED;
            $subscription->grace_ends_at = $graceEnds;
            $subscription->save();

            return $subscription;
        }

        if ($subscription->status === Subscription::STATUS_ACTIVE && $subscription->current_period_end?->isPast()) {
            $graceEnds = $graceDays > 0
                ? $subscription->current_period_end->copy()->addDays($graceDays)
                : $subscription->current_period_end->copy();

            $subscription->status = $graceEnds->isFuture()
                ? Subscription::STATUS_GRACE_PERIOD
                : Subscription::STATUS_EXPIRED;
            $subscription->grace_ends_at = $graceEnds;
            $subscription->save();

            return $subscription;
        }

        if ($subscription->status === Subscription::STATUS_GRACE_PERIOD && $subscription->grace_ends_at?->isPast()) {
            $subscription->status = Subscription::STATUS_EXPIRED;
            $subscription->save();
        }

        return $subscription;
    }

    public function currentUsage(Tenant $tenant): TenantUsageMonthly
    {
        $yearMonth = now()->format('Y-m');

        try {
            return TenantUsageMonthly::firstOrCreate(
                ['tenant_id' => $tenant->id, 'year_month' => $yearMonth],
                ['sync_count' => 0, 'api_calls_count' => 0, 'webhook_count' => 0]
            );
        } catch (QueryException) {
            return TenantUsageMonthly::where('tenant_id', $tenant->id)
                ->where('year_month', $yearMonth)
                ->firstOrFail();
        }
    }

    public function tryReserveSyncSlot(Tenant $tenant, ?int $maxMonthlySyncs): ?int
    {
        $usage = $this->currentUsage($tenant);

        $query = TenantUsageMonthly::query()->where('id', $usage->id);
        if ($maxMonthlySyncs !== null) {
            $query->where('sync_count', '<', $maxMonthlySyncs);
        }

        $affected = $query->increment('sync_count', 1, ['updated_at' => now()]);

        return $affected > 0 ? $usage->id : null;
    }

    public function releaseReservedSyncSlot(int $usageId): void
    {
        TenantUsageMonthly::query()
            ->where('id', $usageId)
            ->where('sync_count', '>', 0)
            ->decrement('sync_count', 1, ['updated_at' => now()]);
    }

    public function incrementUsage(Tenant $tenant, int $syncs = 1, int $apiCalls = 0, int $webhooks = 0): TenantUsageMonthly
    {
        $usage = $this->currentUsage($tenant);

        $deltas = [];
        if ($syncs > 0) {
            $deltas['sync_count'] = $syncs;
        }
        if ($apiCalls > 0) {
            $deltas['api_calls_count'] = $apiCalls;
        }
        if ($webhooks > 0) {
            $deltas['webhook_count'] = $webhooks;
        }

        if ($deltas !== []) {
            TenantUsageMonthly::query()
                ->where('id', $usage->id)
                ->incrementEach($deltas, ['updated_at' => now()]);
            $usage->refresh();
        }

        return $usage;
    }
}
