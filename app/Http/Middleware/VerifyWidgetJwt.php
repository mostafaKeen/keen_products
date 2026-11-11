<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Bitrix24\BitrixJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class VerifyWidgetJwt
{
    public function __construct(
        private readonly BitrixJwtService $jwtService,
    ) {}

    public function handle(Request $request, Closure $next, string ...$allowedScopes): Response
    {
        $token = $request->bearerToken() ?: (string) $request->input('jwt', '');

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

        $request->attributes->set('bitrix_tenant', $tenant);
        $request->attributes->set('bitrix_claims', $claims);

        return $next($request);
    }
}
