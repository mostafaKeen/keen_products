<?php

namespace App\Http\Controllers\Bitrix;

use App\Exceptions\BitrixApiException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessBitrixEventJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixJwtService;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\TenantSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BitrixEntrypointController extends Controller
{
    public function __construct(
        private readonly Bitrix24Client $client,
        private readonly BitrixJwtService $jwtService,
        private readonly SubscriptionGate $gate,
        private readonly TenantSyncService $syncService,
    ) {}

    /**
     * Bitrix24 Installation Wizard URL (GET/POST /bitrix/install).
     */
    public function install(Request $request): InertiaResponse
    {
        $memberId = trim((string) $request->input('member_id', ''));
        $domain = trim((string) $request->input('DOMAIN', ''));
        $authId = trim((string) $request->input('AUTH_ID', ''));
        $refreshId = trim((string) $request->input('REFRESH_ID', ''));
        $appToken = trim((string) $request->input('APPLICATION_TOKEN', ''));
        $serverEndpoint = trim((string) $request->input('SERVER_ENDPOINT', 'https://oauth.bitrix.info/rest/'));
        $expiresIn = max(60, (int) $request->input('AUTH_EXPIRES', 3600));
        $lang = trim((string) $request->input('LANG', 'en')) ?: 'en';
        $appStatus = trim((string) $request->input('status', 'F')) ?: 'F';

        if ($memberId === '' || $domain === '' || ! $this->isValidBitrixDomain($domain)) {
            return Inertia::render('bitrix/install-wizard', [
                'error' => 'Open this Installation Wizard inside your Bitrix24 portal when installing the application.',
                'jwt' => null,
                'tenant' => null,
                'plans' => Plan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get(),
            ]);
        }

        $clientEndpoint = $this->buildClientEndpoint($domain, (string) $request->input('PROTOCOL', '1'));
        $viewer = $this->probeViewer($clientEndpoint, $authId);

        if ($authId !== '' && ! $viewer['verified']) {
            return Inertia::render('bitrix/install-wizard', [
                'error' => 'Could not verify your Bitrix24 administrator session with the portal API.',
                'jwt' => null,
                'tenant' => null,
                'plans' => [],
            ]);
        }

        if ($authId !== '' && ! $viewer['is_admin']) {
            return Inertia::render('bitrix/install-wizard', [
                'error' => 'Only a Bitrix24 administrator can install and provision this application.',
                'jwt' => null,
                'tenant' => null,
                'plans' => [],
            ]);
        }

        $tenant = Tenant::firstOrNew(['member_id' => $memberId]);
        $isReinstall = $tenant->exists && $tenant->install_status === Tenant::INSTALL_UNINSTALLED;

        $tenant->fill([
            'domain' => $domain,
            'client_endpoint' => $clientEndpoint,
            'server_endpoint' => $serverEndpoint !== '' ? $serverEndpoint : 'https://oauth.bitrix.info/rest/',
            'app_status' => $appStatus,
            'language_id' => $lang,
            'installer_b24_user_id' => $viewer['user_id'] ?? $tenant->installer_b24_user_id,
            'installer_name' => $viewer['user_name'] ?? $tenant->installer_name,
        ]);

        if ($authId !== '') {
            $tenant->access_token_enc = $authId;
            $tenant->token_expires_at = now()->addSeconds($expiresIn);
        }
        if ($refreshId !== '') {
            $tenant->refresh_token_enc = $refreshId;
        }
        if ($appToken !== '') {
            $tenant->application_token_enc = $appToken;
        }

        if (! $tenant->exists || $isReinstall || $tenant->install_status === Tenant::INSTALL_UNINSTALLED) {
            $tenant->install_status = Tenant::INSTALL_INSTALLING;
            $tenant->uninstalled_at = null;
        }

        $tenant->save();

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        $jwt = $this->jwtService->issueForTenant(
            $tenant,
            'install',
            $viewer['user_id'] ?? $tenant->installer_b24_user_id,
            true
        );

        return Inertia::render('bitrix/install-wizard', [
            'error' => null,
            'jwt' => $jwt,
            'bx24_script_url' => 'https://api.bitrix24.com/api/v1/',
            'tenant' => $this->serializeTenantForPortal($tenant),
            'subscription' => $this->serializeSubscriptionEval($subEval),
            'plans' => Plan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get(),
            'defaults' => [
                'options_config' => TenantCatalogConfig::defaultOptionsConfig(),
                'cost_types_config' => TenantCatalogConfig::defaultCostTypesConfig(),
                'option_meta_schema' => TenantCatalogConfig::defaultOptionMetaSchema(),
            ],
        ]);
    }

    /**
     * Bitrix24 Application Settings URL (GET/POST /bitrix/app).
     */
    public function app(Request $request): InertiaResponse
    {
        $memberId = trim((string) $request->input('member_id', ''));
        $appToken = trim((string) $request->input('APPLICATION_TOKEN', ''));
        $authId = trim((string) $request->input('AUTH_ID', ''));
        $domain = trim((string) $request->input('DOMAIN', ''));

        if ($memberId === '') {
            return Inertia::render('bitrix/portal-admin', [
                'error' => 'Open this page from your Bitrix24 portal Applications menu.',
                'jwt' => null,
                'is_admin' => false,
                'tenant' => null,
            ]);
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return Inertia::render('bitrix/portal-admin', [
                'error' => 'This Bitrix24 portal is not registered yet. Please reinstall the application.',
                'jwt' => null,
                'is_admin' => false,
                'tenant' => null,
            ]);
        }

        $tokenValid = $tenant->verifyApplicationToken($appToken);
        $viewer = $this->probeViewer($tenant->client_endpoint, $authId);

        if (! $tokenValid && ! $viewer['verified']) {
            return Inertia::render('bitrix/portal-admin', [
                'error' => 'Unable to verify Bitrix24 application signature.',
                'jwt' => null,
                'is_admin' => false,
                'tenant' => null,
            ]);
        }

        if ($domain !== '' && $this->isValidBitrixDomain($domain) && $domain !== $tenant->domain) {
            $tenant->forceFill([
                'domain' => $domain,
                'client_endpoint' => $this->buildClientEndpoint($domain, (string) $request->input('PROTOCOL', '1')),
            ])->saveQuietly();
        }

        if ($authId !== '' && $viewer['verified'] && $viewer['is_admin'] && $request->filled('REFRESH_ID')) {
            $tenant->forceFill([
                'access_token_enc' => $authId,
                'refresh_token_enc' => (string) $request->input('REFRESH_ID'),
                'token_expires_at' => now()->addSeconds(max(60, (int) $request->input('AUTH_EXPIRES', 3600))),
            ])->saveQuietly();
        }

        $isAdmin = $authId !== '' ? (bool) $viewer['is_admin'] : false;
        $b24UserId = $viewer['user_id'] ?? null;

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        $jwt = $this->jwtService->issueForTenant($tenant, 'app', $b24UserId, $isAdmin);

        $tenant->load([
            'catalogConfig',
            'spaBuckets',
            'entityConfigs.fieldMappings',
            'enumMappings',
        ]);

        return Inertia::render('bitrix/portal-admin', [
            'error' => null,
            'jwt' => $jwt,
            'is_admin' => $isAdmin,
            'viewer_name' => $viewer['user_name'] ?? null,
            'tenant' => $this->serializeTenantForPortal($tenant, includeMappings: $isAdmin),
            'subscription' => $this->serializeSubscriptionEval($subEval),
            'plans' => Plan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get(),
            'recent_logs' => $isAdmin
                ? $tenant->syncLogs()->latest('id')->limit(25)->get()
                : [],
        ]);
    }

    /**
     * Bitrix24 CRM Detail Tab Placement Handler (GET/POST /bitrix/widget).
     */
    public function widget(Request $request): InertiaResponse
    {
        $memberId = trim((string) $request->input('member_id', ''));
        $appToken = trim((string) $request->input('APPLICATION_TOKEN', ''));
        $authId = trim((string) $request->input('AUTH_ID', ''));
        $placement = trim((string) $request->input('PLACEMENT', ''));
        $placementOptionsRaw = (string) $request->input('PLACEMENT_OPTIONS', '{}');
        $placementOptions = json_decode($placementOptionsRaw, true);
        if (! is_array($placementOptions)) {
            $placementOptions = [];
        }

        if ($memberId === '') {
            return Inertia::render('bitrix/widget', [
                'error' => 'Missing Bitrix24 placement context.',
                'jwt' => null,
                'context' => null,
            ]);
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return Inertia::render('bitrix/widget', [
                'error' => 'Portal registration not found. Ask your Bitrix24 administrator to reinstall the app.',
                'jwt' => null,
                'context' => null,
            ]);
        }

        if (! $tenant->verifyApplicationToken($appToken)) {
            $viewerCheck = $this->probeViewer($tenant->client_endpoint, $authId);
            if (! $viewerCheck['verified']) {
                return Inertia::render('bitrix/widget', [
                    'error' => 'Invalid Bitrix24 application signature.',
                    'jwt' => null,
                    'context' => null,
                ]);
            }
        }

        $entityTypeId = $this->resolveEntityTypeIdFromPlacement($placement);
        $entityId = (int) ($placementOptions['ID'] ?? 0);

        if (! $entityTypeId || $entityId <= 0) {
            return Inertia::render('bitrix/widget', [
                'error' => "Unsupported placement '{$placement}' or missing CRM record ID.",
                'jwt' => null,
                'context' => null,
            ]);
        }

        $viewer = $authId !== '' ? $this->probeViewer($tenant->client_endpoint, $authId) : ['user_id' => null, 'is_admin' => false];

        $jwt = $this->jwtService->issueForTenant(
            $tenant,
            'widget',
            $viewer['user_id'] ?? null,
            (bool) ($viewer['is_admin'] ?? false),
            [
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
            ]
        );

        try {
            $context = $this->syncService->loadWidgetContext($tenant, $entityTypeId, $entityId);
            $loadError = null;
        } catch (BitrixApiException|\Throwable $e) {
            $context = null;
            $loadError = $e->getMessage();
        }

        return Inertia::render('bitrix/widget', [
            'error' => $loadError,
            'jwt' => $jwt,
            'bx24_script_url' => 'https://api.bitrix24.com/api/v1/',
            'placement' => $placement,
            'context' => $context,
        ]);
    }

    /**
     * Bitrix24 Webhook Event Handler (POST /bitrix/events).
     */
    public function events(Request $request): HttpResponse
    {
        $eventName = strtoupper(trim((string) $request->input('event', '')));
        $auth = (array) $request->input('auth', []);
        $data = (array) $request->input('data', []);

        $memberId = trim((string) ($auth['member_id'] ?? ''));
        $appToken = trim((string) ($auth['application_token'] ?? ''));

        if ($eventName === '' || $memberId === '') {
            return response('INVALID_PAYLOAD', 400);
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return response('TENANT_NOT_FOUND', 200);
        }

        // Lifecycle events
        if ($eventName === 'ONAPPUPDATE') {
            $verified = $tenant->verifyApplicationToken($appToken);
            if (! $verified && ! empty($auth['access_token'])) {
                $viewer = $this->probeViewer($tenant->client_endpoint, (string) $auth['access_token']);
                $verified = $viewer['verified'];
            }
            if (! $verified) {
                return response('FORBIDDEN', 403);
            }

            if ($appToken !== '') {
                $tenant->application_token_enc = $appToken;
            }
            if (! empty($auth['access_token'])) {
                $tenant->access_token_enc = (string) $auth['access_token'];
                $tenant->refresh_token_enc = (string) ($auth['refresh_token'] ?? $tenant->refresh_token_enc);
                $tenant->token_expires_at = now()->addSeconds(max(60, (int) ($auth['expires_in'] ?? 3600)));
            }
            if (! empty($auth['domain']) && $this->isValidBitrixDomain((string) $auth['domain'])) {
                $tenant->domain = (string) $auth['domain'];
            }
            if (! empty($auth['status'])) {
                $tenant->app_status = (string) $auth['status'];
            }
            $tenant->save();

            return response('OK', 200);
        }

        if ($eventName === 'ONAPPUNINSTALL') {
            if (! $tenant->verifyApplicationToken($appToken)) {
                return response('FORBIDDEN', 403);
            }

            $tenant->forceFill([
                'install_status' => Tenant::INSTALL_UNINSTALLED,
                'uninstalled_at' => now(),
                'access_token_enc' => null,
                'refresh_token_enc' => null,
                'token_expires_at' => null,
            ])->save();

            return response('OK', 200);
        }

        if ($eventName === 'ONAPPINSTALL') {
            $tenant->forceFill([
                'install_status' => Tenant::INSTALL_ACTIVE,
                'installed_at' => $tenant->installed_at ?: now(),
                'uninstalled_at' => null,
            ])->save();
            $this->gate->ensureInitialSubscription($tenant);

            return response('OK', 200);
        }

        // CRM & Catalog data events require verified application_token
        if (! $tenant->verifyApplicationToken($appToken)) {
            return response('FORBIDDEN', 403);
        }

        if (! $tenant->isActive()) {
            return response('INACTIVE_PORTAL', 200);
        }

        $fields = (array) ($data['FIELDS'] ?? []);
        $id = (int) ($fields['ID'] ?? 0);

        match ($eventName) {
            'ONCRMDEALADD', 'ONCRMDEALUPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'entity_update', 2, $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMLEADADD', 'ONCRMLEADUPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'entity_update', 1, $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMDYNAMICITEMADD', 'ONCRMDYNAMICITEMUPDATE' => ($id > 0 && ! empty($fields['ENTITY_TYPE_ID']))
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'dynamic_update', (int) $fields['ENTITY_TYPE_ID'], $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMPRODUCTUPDATE', 'CATALOG.PRODUCT.ON.UPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'product_update', 0, $id)->delay(now()->addSeconds(3))
                : null,
            default => null,
        };

        return response('OK', 200);
    }

    /**
     * @return array{verified: bool, is_admin: bool, user_id: ?int, user_name: ?string}
     */
    private function probeViewer(string $clientEndpoint, string $authId): array
    {
        if ($authId === '') {
            return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
        }

        try {
            $base = rtrim($clientEndpoint, '/');
            $adminRes = Http::timeout(10)->asJson()->post($base.'/user.admin.json', ['auth' => $authId])->json();
            if (! is_array($adminRes) || ! empty($adminRes['error'])) {
                return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
            }

            $profileRes = Http::timeout(10)->asJson()->post($base.'/profile.json', ['auth' => $authId])->json();
            $profile = is_array($profileRes['result'] ?? null) ? $profileRes['result'] : [];
            $fullName = trim(($profile['NAME'] ?? '').' '.($profile['LAST_NAME'] ?? ''));

            return [
                'verified' => true,
                'is_admin' => (bool) ($adminRes['result'] ?? false),
                'user_id' => ! empty($profile['ID']) ? (int) $profile['ID'] : null,
                'user_name' => $fullName !== '' ? $fullName : ($profile['EMAIL'] ?? null),
            ];
        } catch (\Throwable) {
            return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
        }
    }

    private function isValidBitrixDomain(string $domain): bool
    {
        return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(:\d+)?$/i', $domain);
    }

    private function buildClientEndpoint(string $domain, string $protocol): string
    {
        $scheme = $protocol === '0' ? 'http' : 'https';

        return "{$scheme}://{$domain}/rest/";
    }

    private function resolveEntityTypeIdFromPlacement(string $placement): ?int
    {
        return match ($placement) {
            'CRM_LEAD_DETAIL_TAB' => 1,
            'CRM_DEAL_DETAIL_TAB' => 2,
            'CRM_QUOTE_DETAIL_TAB' => 7,
            'CRM_SMART_INVOICE_DETAIL_TAB' => 31,
            default => preg_match('/^CRM_DYNAMIC_(\d+)_DETAIL_TAB$/', $placement, $m) ? (int) $m[1] : null,
        };
    }

    private function serializeTenantForPortal(Tenant $tenant, bool $includeMappings = false): array
    {
        $data = [
            'id' => $tenant->id,
            'member_id' => $tenant->member_id,
            'domain' => $tenant->domain,
            'app_status' => $tenant->app_status,
            'install_status' => $tenant->install_status,
            'fee_sync_mode' => $tenant->fee_sync_mode,
            'schema_status' => $tenant->schema_status,
            'license_family' => $tenant->license_family,
            'license_type' => $tenant->license_type,
            'installed_at' => $tenant->installed_at?->toIso8601String(),
            'last_sync_at' => $tenant->last_sync_at?->toIso8601String(),
        ];

        if ($includeMappings) {
            $data['catalog_config'] = $tenant->catalogConfig;
            $data['spa_buckets'] = $tenant->spaBuckets;
            $data['entity_configs'] = $tenant->entityConfigs;
            $data['enum_mappings'] = $tenant->enumMappings;
            $data['schema_snapshot'] = $tenant->schema_snapshot_json;
        }

        return $data;
    }

    private function serializeSubscriptionEval(array $eval): array
    {
        return [
            'allowed' => $eval['allowed'],
            'reason' => $eval['reason'],
            'banner' => $eval['banner'],
            'status' => $eval['status'],
            'plan' => $eval['plan'],
            'subscription' => [
                'id' => $eval['subscription']->id,
                'status' => $eval['subscription']->status,
                'billing_cycle' => $eval['subscription']->billing_cycle,
                'trial_ends_at' => $eval['subscription']->trial_ends_at?->toIso8601String(),
                'current_period_end' => $eval['subscription']->current_period_end?->toIso8601String(),
                'grace_ends_at' => $eval['subscription']->grace_ends_at?->toIso8601String(),
                'upgrade_requested_plan_id' => $eval['subscription']->upgrade_requested_plan_id,
                'upgrade_requested_at' => $eval['subscription']->upgrade_requested_at?->toIso8601String(),
            ],
            'usage' => $eval['usage'],
            'max_monthly_syncs' => $eval['max_monthly_syncs'],
            'remaining_syncs' => $eval['remaining_syncs'],
            'max_options' => $eval['max_options'],
            'features' => $eval['features'],
        ];
    }
}
