<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SyncLog;
use App\Models\Tenant;
use App\Models\TenantUsageMonthly;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(): Response
    {
        $yearMonth = now()->format('Y-m');

        $totalPortals = Tenant::count();
        $activePortals = Tenant::where('install_status', Tenant::INSTALL_ACTIVE)->count();
        $uninstalledPortals = Tenant::where('install_status', Tenant::INSTALL_UNINSTALLED)->count();

        $subscriptions = Subscription::with('plan')->get();
        $trialingCount = $subscriptions->where('status', Subscription::STATUS_TRIALING)->count();
        $paidActiveCount = $subscriptions->where('status', Subscription::STATUS_ACTIVE)->count();
        $expiringSoonCount = $subscriptions->filter(function (Subscription $sub) {
            if ($sub->status === Subscription::STATUS_GRACE_PERIOD) {
                return true;
            }
            $end = $sub->status === Subscription::STATUS_TRIALING ? $sub->trial_ends_at : $sub->current_period_end;

            return $end !== null && $end->isFuture() && $end->lte(now()->addDays(7));
        })->count();

        $mrrCents = $subscriptions
            ->where('status', Subscription::STATUS_ACTIVE)
            ->sum(function (Subscription $sub): int {
                if (! $sub->plan) {
                    return 0;
                }
                if ($sub->billing_cycle === 'yearly' && $sub->plan->price_yearly_cents > 0) {
                    return (int) round($sub->plan->price_yearly_cents / 12);
                }

                return (int) $sub->plan->price_monthly_cents;
            });

        $monthlySyncs = (int) TenantUsageMonthly::where('year_month', $yearMonth)->sum('sync_count');
        $pendingUpgrades = $subscriptions->whereNotNull('upgrade_requested_plan_id')->count();

        // 14-day daily sync breakdown (portable across SQLite/MySQL/Postgres)
        $since = now()->subDays(13)->startOfDay();
        $logsWindow = SyncLog::where('created_at', '>=', $since)->get(['status', 'created_at']);
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $dayLogs = $logsWindow->filter(fn (SyncLog $l) => Carbon::parse($l->created_at)->format('Y-m-d') === $day);
            $series[] = [
                'date' => $day,
                'label' => Carbon::parse($day)->format('M d'),
                'success' => $dayLogs->where('status', 'success')->count(),
                'failed' => $dayLogs->whereIn('status', ['failed', 'blocked'])->count(),
                'skipped' => $dayLogs->where('status', 'skipped')->count(),
            ];
        }

        $upgradeRequests = Subscription::with(['tenant:id,domain,member_id', 'plan:id,name', 'upgradeRequestedPlan:id,name,price_monthly_cents'])
            ->whereNotNull('upgrade_requested_plan_id')
            ->latest('upgrade_requested_at')
            ->limit(8)
            ->get();

        $recentTenants = Tenant::with(['currentSubscription.plan'])
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Tenant $t) use ($yearMonth) {
                $usage = TenantUsageMonthly::where('tenant_id', $t->id)->where('year_month', $yearMonth)->first();

                return [
                    'id' => $t->id,
                    'domain' => $t->domain,
                    'member_id' => $t->member_id,
                    'install_status' => $t->install_status,
                    'schema_status' => $t->schema_status,
                    'fee_sync_mode' => $t->fee_sync_mode,
                    'plan_name' => $t->currentSubscription?->plan?->name,
                    'subscription_status' => $t->currentSubscription?->status,
                    'monthly_syncs' => $usage?->sync_count ?? 0,
                    'last_sync_at' => $t->last_sync_at?->toIso8601String(),
                ];
            });

        $recentRevenueCents = (int) SubscriptionPayment::where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(30))
            ->sum('amount_cents');

        return Inertia::render('admin/dashboard', [
            'kpis' => [
                'total_portals' => $totalPortals,
                'active_portals' => $activePortals,
                'uninstalled_portals' => $uninstalledPortals,
                'trialing' => $trialingCount,
                'paid_active' => $paidActiveCount,
                'expiring_soon' => $expiringSoonCount,
                'monthly_syncs' => $monthlySyncs,
                'mrr_cents' => $mrrCents,
                'recent_revenue_cents' => $recentRevenueCents,
                'pending_upgrades' => $pendingUpgrades,
            ],
            'sync_series' => $series,
            'upgrade_requests' => $upgradeRequests,
            'recent_tenants' => $recentTenants,
        ]);
    }
}
