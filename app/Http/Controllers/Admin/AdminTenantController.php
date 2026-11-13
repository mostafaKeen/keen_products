<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BitrixApiException;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUsageMonthly;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminTenantController extends Controller
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly SubscriptionGate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $installStatus = trim((string) $request->input('install_status', ''));
        $subStatus = trim((string) $request->input('subscription_status', ''));
        $yearMonth = now()->format('Y-m');

        $query = Tenant::query()
            ->with(['currentSubscription.plan', 'currentSubscription.upgradeRequestedPlan'])
            ->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', "%{$search}%")
                    ->orWhere('member_id', 'like', "%{$search}%")
                    ->orWhere('portal_name', 'like', "%{$search}%");
            });
        }

        if ($installStatus !== '') {
            $query->where('install_status', $installStatus);
        }

        if ($subStatus !== '') {
            $query->whereHas('currentSubscription', fn ($q) => $q->where('status', $subStatus));
        }

        $tenants = $query->paginate(20)->withQueryString()->through(function (Tenant $t) use ($yearMonth) {
            $usage = TenantUsageMonthly::where('tenant_id', $t->id)->where('year_month', $yearMonth)->first();
            $sub = $t->currentSubscription;

            return [
                'id' => $t->id,
                'member_id' => $t->member_id,
                'domain' => $t->domain,
                'install_status' => $t->install_status,
                'app_status' => $t->app_status,
                'schema_status' => $t->schema_status,
                'fee_sync_mode' => $t->fee_sync_mode,
                'license_family' => $t->license_family,
                'license_type' => $t->license_type,
                'installed_at' => $t->installed_at?->toIso8601String(),
                'last_sync_at' => $t->last_sync_at?->toIso8601String(),
                'monthly_syncs' => $usage?->sync_count ?? 0,
                'max_monthly_syncs' => $sub?->effectiveMaxMonthlySyncs(),
                'subscription' => $sub ? [
                    'id' => $sub->id,
                    'status' => $sub->status,
                    'billing_cycle' => $sub->billing_cycle,
                    'plan_name' => $sub->plan?->name,
                    'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                    'current_period_end' => $sub->current_period_end?->toIso8601String(),
                    'upgrade_requested_plan' => $sub->upgradeRequestedPlan?->name,
                ] : null,
            ];
        });

        return Inertia::render('admin/tenants/index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $search,
                'install_status' => $installStatus,
                'subscription_status' => $subStatus,
            ],
        ]);
    }

    public function show(Tenant $tenant): Response
    {
        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);

        $tenant->load([
            'catalogConfig',
            'spaBuckets',
            'entityConfigs.fieldMappings',
            'enumMappings',
            'payments.plan',
            'payments.recordedBy:id,name',
        ]);

        return Inertia::render('admin/tenants/show', [
            'tenant' => [
                'id' => $tenant->id,
                'member_id' => $tenant->member_id,
                'domain' => $tenant->domain,
                'client_endpoint' => $tenant->client_endpoint,
                'app_status' => $tenant->app_status,
                'install_status' => $tenant->install_status,
                'license_family' => $tenant->license_family,
                'license_type' => $tenant->license_type,
                'language_id' => $tenant->language_id,
                'installer_name' => $tenant->installer_name,
                'installer_b24_user_id' => $tenant->installer_b24_user_id,
                'fee_sync_mode' => $tenant->fee_sync_mode,
                'schema_status' => $tenant->schema_status,
                'installed_at' => $tenant->installed_at?->toIso8601String(),
                'uninstalled_at' => $tenant->uninstalled_at?->toIso8601String(),
                'token_expires_at' => $tenant->token_expires_at?->toIso8601String(),
                'last_api_call_at' => $tenant->last_api_call_at?->toIso8601String(),
                'last_sync_at' => $tenant->last_sync_at?->toIso8601String(),
                'has_tokens' => ! empty($tenant->access_token_enc) && ! empty($tenant->refresh_token_enc),
                'catalog_config' => $tenant->catalogConfig,
                'spa_buckets' => $tenant->spaBuckets,
                'entity_configs' => $tenant->entityConfigs,
                'enum_mappings_count' => $tenant->enumMappings->count(),
                'allowed_b24_user_ids' => $tenant->allowed_b24_user_ids,
                'cached_b24_users' => $tenant->settings_json['cached_b24_users'] ?? [],
                'payments' => $tenant->payments()->latest('id')->limit(20)->get(),
            ],
            'subscription' => [
                'id' => $subEval['subscription']->id,
                'plan_id' => $subEval['subscription']->plan_id,
                'seats_count' => $subEval['subscription']->effectiveSeatsCount(),
                'calculated_price_monthly_cents' => $subEval['subscription']->calculateMonthlyPriceCents(),
                'status' => $subEval['subscription']->status,
                'billing_cycle' => $subEval['subscription']->billing_cycle,
                'billing_provider' => $subEval['subscription']->billing_provider,
                'trial_ends_at' => $subEval['subscription']->trial_ends_at?->toDateTimeString(),
                'current_period_end' => $subEval['subscription']->current_period_end?->toDateTimeString(),
                'grace_ends_at' => $subEval['subscription']->grace_ends_at?->toDateTimeString(),
                'custom_limits_json' => $subEval['subscription']->custom_limits_json,
                'admin_notes' => $subEval['subscription']->admin_notes,
                'upgrade_requested_plan' => $subEval['subscription']->upgradeRequestedPlan,
                'plan' => $subEval['plan'],
                'usage' => $subEval['usage'],
                'max_monthly_syncs' => $subEval['max_monthly_syncs'],
                'max_options' => $subEval['max_options'],
            ],
            'usage_history' => $tenant->monthlyUsage()->orderByDesc('year_month')->limit(6)->get(),
            'sync_logs' => $tenant->syncLogs()->latest('id')->limit(30)->get(),
            'plans' => Plan::orderBy('sort_order')->get(),
        ]);
    }

    public function probe(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $res = $this->client->call($tenant, 'app.info');
            $info = (array) ($res['result'] ?? []);
            if (! empty($info['LICENSE'])) {
                $licenseType = (string) $info['LICENSE'];
                $tenant->forceFill([
                    'license_type' => $licenseType,
                    'license_family' => str_contains(strtolower($licenseType), 'ent') ? 'ent' : 'std',
                ])->save();
            }

            AdminAuditLog::create([
                'user_id' => $request->user()->id,
                'tenant_id' => $tenant->id,
                'action' => 'tenant_health_probe',
                'details_json' => ['installed' => $info['INSTALLED'] ?? null, 'license' => $info['LICENSE'] ?? null],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return back()->with('success', 'Portal health probe succeeded (LICENSE: '.($info['LICENSE'] ?? 'unknown').').');
        } catch (BitrixApiException $e) {
            return back()->with('error', 'Health probe failed: '.$e->getMessage());
        }
    }

    public function updateStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'install_status' => ['required', 'in:active,suspended'],
        ]);

        $old = $tenant->install_status;
        $tenant->forceFill(['install_status' => $validated['install_status']])->save();

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant_status_changed',
            'details_json' => ['from' => $old, 'to' => $validated['install_status']],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('success', "Portal status changed from {$old} to {$validated['install_status']}.");
    }

    public function fetchBitrixUsers(Request $request, Tenant $tenant): RedirectResponse
    {
        try {
            $res = $this->client->call($tenant, 'user.get', [
                'sort' => 'LAST_NAME',
                'order' => 'ASC',
                'FILTER' => ['ACTIVE' => 'Y'],
            ]);

            $rawUsers = (array) ($res['result'] ?? []);
            $users = [];
            foreach ($rawUsers as $u) {
                if (empty($u['ID'])) {
                    continue;
                }
                $firstName = trim((string) ($u['NAME'] ?? ''));
                $lastName = trim((string) ($u['LAST_NAME'] ?? ''));
                $fullName = trim("{$firstName} {$lastName}");
                $users[] = [
                    'id' => (int) $u['ID'],
                    'name' => $fullName !== '' ? $fullName : ('User #'.$u['ID']),
                    'email' => (string) ($u['EMAIL'] ?? ''),
                    'work_position' => (string) ($u['WORK_POSITION'] ?? ''),
                    'active' => ($u['ACTIVE'] ?? 'Y') === 'Y',
                ];
            }

            $settings = $tenant->settings_json ?? [];
            $settings['cached_b24_users'] = $users;
            $tenant->settings_json = $settings;
            $tenant->save();

            AdminAuditLog::create([
                'user_id' => $request->user()->id,
                'tenant_id' => $tenant->id,
                'action' => 'tenant_b24_users_fetched',
                'details_json' => ['user_count' => count($users)],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return back()->with('success', 'Fetched '.count($users).' Bitrix24 users from portal.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to fetch Bitrix24 users: '.$e->getMessage());
        }
    }

    public function updateAllowedUsers(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'restrict_users' => ['required', 'boolean'],
            'allowed_b24_user_ids' => ['nullable', 'array'],
            'allowed_b24_user_ids.*' => ['integer'],
            'seats_count' => ['nullable', 'integer', 'min:1'],
        ]);

        $restrict = (bool) $validated['restrict_users'];
        $allowed = $restrict
            ? array_values(array_unique(array_map('intval', $validated['allowed_b24_user_ids'] ?? [])))
            : null;

        $tenant->allowed_b24_user_ids = $allowed;
        $tenant->save();

        $sub = $this->gate->ensureInitialSubscription($tenant);
        $sub->loadMissing('plan');

        $seats = $validated['seats_count'] ?? ($allowed !== null ? max(1, count($allowed)) : null);
        if ($seats !== null) {
            $sub->update(['seats_count' => max(1, (int) $seats)]);
        }

        AdminAuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant_allowed_users_updated',
            'details_json' => [
                'restrict_users' => $restrict,
                'allowed_b24_user_ids' => $allowed,
                'seats_count' => $sub->seats_count,
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $message = $restrict
            ? 'Updated allowed Bitrix24 users ('.count($allowed ?? []).' users assigned to '.$sub->seats_count.' seats).'
            : 'User restriction disabled. All portal users can now access the application.';

        return back()->with('success', $message);
    }
}
