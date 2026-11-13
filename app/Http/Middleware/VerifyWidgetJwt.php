<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Bitrix24\BitrixJwtService;
use App\Services\Subscriptions\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class VerifyWidgetJwt
{
    public function __construct(
        private readonly BitrixJwtService $jwtService,
        private readonly SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next, string ...$allowedScopes): Response
    {
        $token = (string) ($request->bearerToken() ?? '');

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing iframe authorization token.',
            ], 401);
        }

        try {
            $claims = $this->jwtService->verify($token);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        }

        $scopeMode = (string) ($claims['scope_mode'] ?? '');
        if ($allowedScopes !== [] && ! in_array($scopeMode, $allowedScopes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient iframe session scope.',
            ], 403);
        }

        if (in_array($scopeMode, ['app', 'install'], true) && empty($claims['is_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Bitrix24 administrator permission is required for portal configuration.',
            ], 403);
        }

        $tenant = Tenant::query()
            ->where('id', $claims['tenant_id'] ?? 0)
            ->where('member_id', $claims['member_id'] ?? '')
            ->first();

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Portal tenant not found.',
            ], 404);
        }

        if ($tenant->install_status === Tenant::INSTALL_SUSPENDED) {
            return response()->json([
                'success' => false,
                'message' => 'This Bitrix24 portal account is suspended. Please contact app support.',
            ], 403);
        }

        if ($tenant->install_status === Tenant::INSTALL_UNINSTALLED) {
            return response()->json([
                'success' => false,
                'message' => 'The application has been uninstalled from this Bitrix24 portal.',
            ], 403);
        }

        $subEval = $this->gate->evaluate($tenant, requireSyncQuota: false);
        if (! $subEval['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $subEval['reason'] ?? 'Subscription is inactive. Please contact support.',
            ], 403);
        }

        $b24UserId = isset($claims['b24_user_id']) ? (int) $claims['b24_user_id'] : null;
        if (! $tenant->isUserAllowed($b24UserId)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied: Your Bitrix24 user account is not authorized to access this application.',
            ], 403);
        }

        $request->attributes->set('bitrix_tenant', $tenant);
        $request->attributes->set('bitrix_claims', $claims);

        return $next($request);
    }
}
