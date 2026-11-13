<?php

namespace App\Http\Controllers\Bitrix;

use App\Exceptions\BitrixApiException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessBitrixEventJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixDomainGuard;
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
        private readonly BitrixDomainGuard $domainGuard,
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
        $domain = strtolower(trim((string) $request->input('DOMAIN', '')));
        $authId = trim((string) $request->input('AUTH_ID', ''));
        $refreshId = trim((string) $request->input('REFRESH_ID', ''));
        $appToken = trim((string) $request->input('APPLICATION_TOKEN', ''));
        $serverEndpoint = trim((string) $request->input('SERVER_ENDPOINT', 'https://oauth.bitrix.info/rest/'));
        $expiresIn = max(60, (int) $request->input('AUTH_EXPIRES', 3600));
        $rawLang = trim((string) $request->input('LANG', 'en'));
        $lang = preg_match('/^[a-zA-Z]{2}(?:[-_][a-zA-Z0-9]{2,8})?$/', $rawLang) ? strtolower($rawLang) : 'en';
        $appStatus = $this->normalizeAppStatus((string) $request->input('status', 'F')) ?? 'F';

        if (
            ! $this->isValidMemberId($memberId)
            || $domain === ''
            || ! $this->domainGuard->isSafeDomain($domain)
        ) {
            return $this->installErrorResponse(
                'Open this Installation Wizard inside your Bitrix24 portal when installing the application.',
                Plan::cachedPublicPlans()
            );
        }

        if ($authId === '') {
            return $this->installErrorResponse(
                'Missing Bitrix24 administrator session token (AUTH_ID). Reopen the Installation Wizard inside Bitrix24.'
            );
        }

        $existingTenant = Tenant::where('member_id', $memberId)->first();
        if ($existingTenant && $existingTenant->install_status === Tenant::INSTALL_SUSPENDED) {
            return $this->installErrorResponse(
                'This Bitrix24 portal account is suspended. Please contact app support at apps@keenenter.com.'
            );
        }

        $isReinstall = $existingTenant !== null && $existingTenant->install_status === Tenant::INSTALL_UNINSTALLED;

        // Enforce Marketplace Cloud / allowlisted Self-Hosted portal origin and signature BEFORE probing outbound URLs.
        if (! $existingTenant || $isReinstall) {
            if (! $this->domainGuard->isAllowedInstallDomain($domain)) {
                return $this->installErrorResponse(
                    'This domain is not authorized for Bitrix24 Marketplace installation. Contact support at apps@keenenter.com to allowlist a Self-Hosted Bitrix24 domain.'
                );
            }

            if ($appToken === '') {
                return $this->installErrorResponse(
                    'Missing Bitrix24 installation signature (APPLICATION_TOKEN). Reopen the Installation Wizard inside Bitrix24.'
                );
            }
        } elseif (
            ! empty($existingTenant->application_token_enc)
            && ($appToken === '' || ! $existingTenant->verifyApplicationToken($appToken))
        ) {
            return $this->installErrorResponse('Invalid Bitrix24 application signature.');
        }

        // Always verify AUTH_ID against the already-registered portal endpoint when the tenant exists,
        // preventing attacker-controlled DOMAIN parameters from hijacking client_endpoint or leaking tokens.
        $probeEndpoint = ($existingTenant && ! empty($existingTenant->client_endpoint))
            ? $existingTenant->client_endpoint
            : $this->buildClientEndpoint($domain);

        if (! $this->domainGuard->isSafeHttpsUrl($probeEndpoint)) {
            return $this->installErrorResponse('Portal REST endpoint failed safety verification.');
        }

        $viewer = $this->probeViewer($probeEndpoint, $authId);

        if (! $viewer['verified']) {
            return $this->installErrorResponse('Could not verify your Bitrix24 administrator session with the portal API.');
        }

        if (! $viewer['is_admin']) {
            return $this->installErrorResponse('Only a Bitrix24 administrator can install and provision this application.');
        }

        $tenant = $existingTenant ?? new Tenant(['member_id' => $memberId]);
        $canChangeDomain = (
            ! $existingTenant
            || $isReinstall
            || empty($existingTenant->application_token_enc)
            || $existingTenant->verifyApplicationToken($appToken)
        ) && $this->domainGuard->isAllowedInstallDomain($domain);
        $resolvedDomain = $canChangeDomain ? $domain : $existingTenant->domain;
        $clientEndpoint = $canChangeDomain ? $this->buildClientEndpoint($resolvedDomain) : $existingTenant->client_endpoint;
        $safeServerEndpoint = $this->domainGuard->isTrustedOauthUrl($serverEndpoint, $resolvedDomain)
            ? $serverEndpoint
            : 'https://oauth.bitrix.info/rest/';

        $tenant->fill([
            'domain' => $resolvedDomain,
            'client_endpoint' => $clientEndpoint,
            'server_endpoint' => $safeServerEndpoint,
            'app_status' => $appStatus,
            'language_id' => $lang,
            'installer_b24_user_id' => $viewer['user_id'] ?? $tenant->installer_b24_user_id,
            'installer_name' => $viewer['user_name'] ?? $tenant->installer_name,
        ]);

        $tenant->access_token_enc = $authId;
        $tenant->token_expires_at = now()->addSeconds($expiresIn);
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
            'plans' => Plan::cachedPublicPlans(),
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
        $domain = strtolower(trim((string) $request->input('DOMAIN', '')));

        if (! $this->isValidMemberId($memberId)) {
            return $this->appErrorResponse('Open this page from your Bitrix24 portal Applications menu.');
        }

        if ($authId === '') {
            return $this->appErrorResponse('Unable to verify your Bitrix24 session. Open this page inside Bitrix24.');
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return $this->appErrorResponse('This Bitrix24 portal is not registered yet. Please reinstall the application.');
        }

        if ($tenant->install_status === Tenant::INSTALL_UNINSTALLED) {
            return $this->appErrorResponse('This Bitrix24 portal has uninstalled the application. Please reinstall from Bitrix24 Marketplace.');
        }

        if ($tenant->install_status === Tenant::INSTALL_SUSPENDED) {
            return $this->appErrorResponse('This Bitrix24 portal account is suspended. Please contact app support at apps@keenenter.com.');
        }

        if (! empty($tenant->application_token_enc) && $appToken !== '' && ! $tenant->verifyApplicationToken($appToken)) {
            return $this->appErrorResponse('Unable to verify Bitrix24 application signature.');
        }

        if (empty($tenant->client_endpoint) || ! $this->domainGuard->isSafeHttpsUrl($tenant->client_endpoint)) {
            return $this->appErrorResponse('Portal REST endpoint failed safety verification.');
        }

        $viewer = $this->probeViewer($tenant->client_endpoint, $authId);

        if (! $viewer['verified']) {
            return $this->appErrorResponse('Unable to verify your Bitrix24 session. Open this page inside Bitrix24.');
        }

        $hasVerifiedAppToken = empty($tenant->application_token_enc)
            || ($appToken !== '' && $tenant->verifyApplicationToken($appToken));

        if (
            $viewer['is_admin']
            && $hasVerifiedAppToken
            && $domain !== ''
            && $domain !== strtolower((string) $tenant->domain)
            && $this->domainGuard->isAllowedInstallDomain($domain)
        ) {
            $tenant->forceFill([
                'domain' => $domain,
                'client_endpoint' => $this->buildClientEndpoint($domain),
            ])->saveQuietly();
        }

        $refreshId = trim((string) $request->input('REFRESH_ID', ''));
        if ($viewer['is_admin'] && $hasVerifiedAppToken && $refreshId !== '') {
            $tenant->forceFill([
                'access_token_enc' => $authId,
                'refresh_token_enc' => $refreshId,
                'token_expires_at' => now()->addSeconds(max(60, (int) $request->input('AUTH_EXPIRES', 3600))),
            ])->saveQuietly();
        }

        $isAdmin = (bool) $viewer['is_admin'];
        $b24UserId = $viewer['user_id'] ?? null;

        if (! $tenant->isUserAllowed($b24UserId)) {
            $userTag = $b24UserId ? " (User ID #{$b24UserId})" : '';

            return $this->appErrorResponse("Access Denied: Your Bitrix24 user account{$userTag} has not been granted access to this application. Please contact your portal administrator or reach support at apps@keenenter.com to assign a license seat.");
        }

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
            'plans' => Plan::cachedPublicPlans(),
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
        $placementOptions = strlen($placementOptionsRaw) <= 16384
            ? json_decode($placementOptionsRaw, true)
            : null;
        if (! is_array($placementOptions)) {
            $placementOptions = [];
        }

        if (! $this->isValidMemberId($memberId)) {
            return $this->widgetErrorResponse('Missing Bitrix24 placement context.');
        }

        if ($authId === '') {
            return $this->widgetErrorResponse('Unable to verify your Bitrix24 session. Open this tab inside Bitrix24.');
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return $this->widgetErrorResponse('Portal registration not found. Ask your Bitrix24 administrator to reinstall the app.');
        }

        if (! $tenant->isActive()) {
            return $this->widgetErrorResponse('This application is not active on this Bitrix24 portal.');
        }

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return $this->widgetErrorResponse($subEval['reason'] ?? 'Subscription is inactive. Please contact support at apps@keenenter.com.');
        }

        if (! empty($tenant->application_token_enc) && $appToken !== '' && ! $tenant->verifyApplicationToken($appToken)) {
            return $this->widgetErrorResponse('Invalid Bitrix24 application signature.');
        }

        if (empty($tenant->client_endpoint) || ! $this->domainGuard->isSafeHttpsUrl($tenant->client_endpoint)) {
            return $this->widgetErrorResponse('Portal REST endpoint failed safety verification.');
        }

        $viewer = $this->probeViewer($tenant->client_endpoint, $authId);
        if (! $viewer['verified']) {
            return $this->widgetErrorResponse('Unable to verify your Bitrix24 session. Open this tab inside Bitrix24.');
        }

        $b24UserId = $viewer['user_id'] ?? null;
        if (! $tenant->isUserAllowed($b24UserId)) {
            $userTag = $b24UserId ? " (User ID #{$b24UserId})" : '';

            return $this->widgetErrorResponse("Access Denied: Your Bitrix24 user account{$userTag} has not been granted a license to use this widget. Please contact your portal administrator or reach support at apps@keenenter.com to assign a license seat.");
        }

        $entityTypeId = $this->resolveEntityTypeIdFromPlacement($placement);
        $entityId = filter_var($placementOptions['ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! $entityTypeId || $entityId === false) {
            return $this->widgetErrorResponse("Unsupported placement '{$placement}' or missing CRM record ID.");
        }

        if (! $viewer['is_admin'] && ! $this->canViewerAccessEntity($tenant->client_endpoint, $authId, $entityTypeId, $entityId)) {
            return $this->widgetErrorResponse('You do not have Bitrix24 permission to access this CRM entity.');
        }

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
            'can_manage_catalog' => (bool) ($viewer['is_admin'] ?? false),
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

        if ($eventName === '' || ! $this->isValidMemberId($memberId)) {
            return response('INVALID_PAYLOAD', 400);
        }

        $tenant = Tenant::where('member_id', $memberId)->first();
        if (! $tenant) {
            return response('TENANT_NOT_FOUND', 200);
        }

        // Lifecycle events
        if ($eventName === 'ONAPPUPDATE') {
            if ($tenant->install_status === Tenant::INSTALL_SUSPENDED) {
                return response('FORBIDDEN', 403);
            }

            $verified = $tenant->verifyApplicationToken($appToken);
            if (
                ! $verified
                && $appToken !== ''
                && ! empty($auth['access_token'])
                && ! empty($tenant->client_endpoint)
                && $this->domainGuard->isSafeHttpsUrl($tenant->client_endpoint)
            ) {
                $viewer = $this->probeViewer($tenant->client_endpoint, (string) $auth['access_token']);
                $verified = $viewer['verified'] && $viewer['is_admin'];
            }
            if (! $verified) {
                return response('FORBIDDEN', 403);
            }

            if ($appToken !== '') {
                $tenant->application_token_enc = $appToken;
            }
            if (! empty($auth['access_token'])) {
                $tenant->access_token_enc = (string) $auth['access_token'];
                if (! empty($auth['refresh_token'])) {
                    $tenant->refresh_token_enc = (string) $auth['refresh_token'];
                }
                $tenant->token_expires_at = now()->addSeconds(max(60, (int) ($auth['expires_in'] ?? 3600)));
            }
            if (! empty($auth['domain'])) {
                $authDomain = strtolower(trim((string) $auth['domain']));
                if ($authDomain !== strtolower((string) $tenant->domain) && $this->domainGuard->isAllowedInstallDomain($authDomain)) {
                    $tenant->domain = $authDomain;
                    $tenant->client_endpoint = $this->buildClientEndpoint($authDomain);
                }
            }
            if (! empty($auth['status'])) {
                $normalizedStatus = $this->normalizeAppStatus((string) $auth['status']);
                if ($normalizedStatus !== null) {
                    $tenant->app_status = $normalizedStatus;
                }
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
            if (! $tenant->verifyApplicationToken($appToken) || $tenant->install_status === Tenant::INSTALL_SUSPENDED) {
                return response('FORBIDDEN', 403);
            }

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
        $id = filter_var($fields['ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $dynamicEntityTypeId = filter_var($fields['ENTITY_TYPE_ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

        match ($eventName) {
            'ONCRMDEALADD', 'ONCRMDEALUPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'entity_update', 2, $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMLEADADD', 'ONCRMLEADUPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'entity_update', 1, $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMDYNAMICITEMADD', 'ONCRMDYNAMICITEMUPDATE' => ($id > 0 && $dynamicEntityTypeId > 0)
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'dynamic_update', $dynamicEntityTypeId, $id)->delay(now()->addSeconds(3))
                : null,
            'ONCRMPRODUCTUPDATE', 'CATALOG.PRODUCT.ON.UPDATE' => $id > 0
                ? ProcessBitrixEventJob::dispatch($tenant->id, 'product_update', 0, $id)->delay(now()->addSeconds(3))
                : null,
            default => null,
        };

        return response('OK', 200);
    }

    private function isValidMemberId(string $memberId): bool
    {
        return $memberId !== '' && preg_match('/^[a-zA-Z0-9._-]{4,128}$/', $memberId) === 1;
    }

    private function normalizeAppStatus(string $status): ?string
    {
        $normalized = strtoupper(trim($status));

        return in_array($normalized, ['F', 'L', 'T', 'P', 'D', 'N'], true) ? $normalized : null;
    }

    /**
     * @param  iterable<mixed>  $plans
     */
    private function installErrorResponse(string $message, iterable $plans = []): InertiaResponse
    {
        return Inertia::render('bitrix/install-wizard', [
            'error' => $message,
            'jwt' => null,
            'tenant' => null,
            'plans' => $plans,
        ]);
    }

    private function appErrorResponse(string $message): InertiaResponse
    {
        return Inertia::render('bitrix/portal-admin', [
            'error' => $message,
            'jwt' => null,
            'is_admin' => false,
            'tenant' => null,
        ]);
    }

    private function widgetErrorResponse(string $message): InertiaResponse
    {
        return Inertia::render('bitrix/widget', [
            'error' => $message,
            'jwt' => null,
            'context' => null,
        ]);
    }

    /**
     * @return array{verified: bool, is_admin: bool, user_id: ?int, user_name: ?string}
     */
    private function probeViewer(string $clientEndpoint, string $authId): array
    {
        if ($authId === '') {
            return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
        }

        $httpOptions = $this->domainGuard->pinnedHttpOptions($clientEndpoint);
        if ($httpOptions === null) {
            return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
        }

        try {
            $base = rtrim($clientEndpoint, '/');
            $adminRes = Http::timeout(10)->withOptions($httpOptions)->asJson()
                ->post($base.'/user.admin.json', ['auth' => $authId])
                ->json();
            if (! is_array($adminRes) || ! empty($adminRes['error'])) {
                return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
            }

            $profileRes = Http::timeout(10)->withOptions($httpOptions)->asJson()
                ->post($base.'/profile.json', ['auth' => $authId])
                ->json();
            $profile = is_array($profileRes['result'] ?? null) ? (array) $profileRes['result'] : [];

            if (empty($profile['ID'])) {
                try {
                    $userRes = Http::timeout(10)->withOptions($httpOptions)->asJson()
                        ->post($base.'/user.current.json', ['auth' => $authId])
                        ->json();
                    if (is_array($userRes['result'] ?? null) && ! empty($userRes['result']['ID'])) {
                        $profile = (array) $userRes['result'];
                    }
                } catch (\Throwable) {
                    // Ignore fallback error
                }
            }

            $userId = ! empty($profile['ID']) ? (int) $profile['ID'] : null;
            if ($userId === null) {
                $reqId = request()->input('USER_ID') ?? request()->input('auth.user_id');
                if ($reqId !== null && is_numeric($reqId) && (int) $reqId > 0) {
                    $userId = (int) $reqId;
                }
            }

            $fullName = trim(($profile['NAME'] ?? '').' '.($profile['LAST_NAME'] ?? ''));

            return [
                'verified' => true,
                'is_admin' => (bool) ($adminRes['result'] ?? false),
                'user_id' => $userId,
                'user_name' => $fullName !== '' ? $fullName : ($profile['EMAIL'] ?? null),
            ];
        } catch (\Throwable) {
            return ['verified' => false, 'is_admin' => false, 'user_id' => null, 'user_name' => null];
        }
    }

    private function canViewerAccessEntity(string $clientEndpoint, string $authId, int $entityTypeId, int $entityId): bool
    {
        if ($authId === '' || $entityId <= 0) {
            return false;
        }

        $httpOptions = $this->domainGuard->pinnedHttpOptions($clientEndpoint);
        if ($httpOptions === null) {
            return false;
        }

        try {
            $base = rtrim($clientEndpoint, '/');
            $http = Http::timeout(10)->withOptions($httpOptions)->asJson();
            if ($entityTypeId === 1) {
                $res = $http->post($base.'/crm.lead.get.json', ['id' => $entityId, 'auth' => $authId])->json();

                return is_array($res) && empty($res['error']) && ! empty($res['result']);
            }
            if ($entityTypeId === 2) {
                $res = $http->post($base.'/crm.deal.get.json', ['id' => $entityId, 'auth' => $authId])->json();

                return is_array($res) && empty($res['error']) && ! empty($res['result']);
            }
            if ($entityTypeId === 7) {
                $res = $http->post($base.'/crm.quote.get.json', ['id' => $entityId, 'auth' => $authId])->json();

                return is_array($res) && empty($res['error']) && ! empty($res['result']);
            }

            $res = $http->post($base.'/crm.item.get.json', [
                'entityTypeId' => $entityTypeId,
                'id' => $entityId,
                'auth' => $authId,
            ])->json();

            if (! is_array($res) || ! empty($res['error'])) {
                return false;
            }

            $hasItemKey = is_array($res['result'] ?? null) && array_key_exists('item', $res['result']);

            return $hasItemKey ? ! empty($res['result']['item']) : ! empty($res['result']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildClientEndpoint(string $domain): string
    {
        return "https://{$domain}/rest/";
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
