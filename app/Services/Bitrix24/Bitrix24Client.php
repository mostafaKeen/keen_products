<?php

namespace App\Services\Bitrix24;

use App\Exceptions\BitrixApiException;
use App\Models\Tenant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Bitrix24Client
{
    private int $callCounter = 0;

    private ?float $maxOperating = null;

    public function resetMetrics(): void
    {
        $this->callCounter = 0;
        $this->maxOperating = null;
    }

    /**
     * @return array{calls: int, operating_max: ?float}
     */
    public function getMetrics(): array
    {
        return [
            'calls' => $this->callCounter,
            'operating_max' => $this->maxOperating,
        ];
    }

    /**
     * Execute a Bitrix24 REST API method for a specific tenant.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(
        Tenant $tenant,
        string $method,
        array $params = [],
        ?string $overrideAccessToken = null,
        bool $retriedAfterRefresh = false,
        bool $retriedAfterThrottle = false,
    ): array {
        $token = $overrideAccessToken ?: $tenant->access_token_enc;
        if (! $token) {
            throw new BitrixApiException('NO_AUTH_FOUND', 'No Bitrix24 access token is stored for this portal.', 401);
        }

        $this->respectLeakyBucket($tenant);

        $endpoint = $this->buildMethodUrl($tenant, $method);
        $payload = array_merge($params, ['auth' => $token]);

        $this->callCounter++;

        /** @var Response $response */
        $response = Http::timeout(25)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, $payload);

        $data = $response->json();
        if (! is_array($data)) {
            throw new BitrixApiException(
                'ERROR_UNEXPECTED_ANSWER',
                'Non-JSON response from Bitrix24 REST endpoint.',
                $response->status()
            );
        }

        $this->captureOperatingMetric($data);

        $tenant->forceFill(['last_api_call_at' => now()])->saveQuietly();

        if (! empty($data['error']) || $response->status() >= 400) {
            $errorCode = (string) ($data['error'] ?? ('HTTP_'.$response->status()));
            $errorDesc = (string) ($data['error_description'] ?? $response->body());
            $operatingResetAt = isset($data['time']['operating_reset_at'])
                ? (int) $data['time']['operating_reset_at']
                : null;

            $exception = new BitrixApiException(
                $errorCode,
                $errorDesc,
                $response->status(),
                $operatingResetAt,
                $data
            );

            // Reactive OAuth renewal strictly upon expired_token and single-flight locked per tenant.
            if ($exception->isExpiredToken() && ! $retriedAfterRefresh && $overrideAccessToken === null) {
                $refreshed = $this->refreshToken($tenant, $token);

                return $this->call($refreshed, $method, $params, null, true, $retriedAfterThrottle);
            }

            // Brief single retry for transient QUERY_LIMIT_EXCEEDED bursts during interactive requests.
            if ($exception->isRateLimited() && ! $retriedAfterThrottle) {
                usleep(600_000);

                return $this->call($tenant, $method, $params, $overrideAccessToken, $retriedAfterRefresh, true);
            }

            throw $exception;
        }

        return $data;
    }

    /**
     * Execute multiple REST commands via Bitrix24 `batch` (automatically chunked to 50 commands per request).
     *
     * @param  array<string, array{method: string, params?: array<string, mixed>}>  $commands
     * @return array<string, mixed>
     */
    public function batch(
        Tenant $tenant,
        array $commands,
        bool $halt = false,
        ?string $overrideAccessToken = null,
    ): array {
        if ($commands === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($commands, 50, true) as $chunk) {
            $cmdMap = [];
            foreach ($chunk as $key => $spec) {
                $method = $spec['method'];
                $params = $spec['params'] ?? [];
                $query = $params !== [] ? '?'.http_build_query($params) : '';
                $cmdMap[$key] = $method.$query;
            }

            $response = $this->call($tenant, 'batch', [
                'halt' => $halt ? 1 : 0,
                'cmd' => $cmdMap,
            ], $overrideAccessToken);

            $chunkResult = $response['result']['result'] ?? [];
            $chunkErrors = $response['result']['result_error'] ?? [];

            foreach ($chunk as $key => $_) {
                $results[$key] = [
                    'result' => $chunkResult[$key] ?? null,
                    'error' => $chunkErrors[$key] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Refresh the Bitrix24 OAuth 2.0 token pair under a distributed per-tenant lock.
     */
    public function refreshToken(Tenant $tenant, ?string $failedAccessToken = null): Tenant
    {
        return Cache::lock("b24_refresh_{$tenant->member_id}", 15)->block(10, function () use ($tenant, $failedAccessToken) {
            $fresh = Tenant::findOrFail($tenant->id);

            // If a sibling worker already rotated the token while we waited for the lock, reuse it cleanly.
            if (
                $failedAccessToken !== null
                && $fresh->access_token_enc !== null
                && $fresh->access_token_enc !== $failedAccessToken
            ) {
                return $fresh;
            }

            $refreshToken = $fresh->refresh_token_enc;
            if (! $refreshToken) {
                throw new BitrixApiException('NO_REFRESH_TOKEN', 'Refresh token missing for portal.', 401);
            }

            $clientId = (string) config('services.bitrix24.client_id');
            $clientSecret = (string) config('services.bitrix24.client_secret');
            $oauthUrl = (string) config('services.bitrix24.oauth_url', 'https://oauth.bitrix.info/oauth/token/');

            if ($clientId === '' || $clientSecret === '') {
                throw new BitrixApiException(
                    'APP_CREDENTIALS_MISSING',
                    'Bitrix24 client_id / client_secret are not configured on the server.',
                    500
                );
            }

            $response = Http::timeout(20)->get($oauthUrl, [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]);

            $data = $response->json();
            if (! is_array($data) || ! empty($data['error']) || empty($data['access_token'])) {
                Log::warning('Bitrix24 OAuth refresh failed', [
                    'member_id' => $fresh->member_id,
                    'domain' => $fresh->domain,
                    'status' => $response->status(),
                    'error' => $data['error'] ?? null,
                ]);

                throw new BitrixApiException(
                    (string) ($data['error'] ?? 'OAUTH_REFRESH_FAILED'),
                    (string) ($data['error_description'] ?? 'Failed to refresh Bitrix24 OAuth token.'),
                    $response->status() ?: 401,
                    payload: is_array($data) ? $data : []
                );
            }

            $expiresIn = max(60, (int) ($data['expires_in'] ?? 3600));

            $fresh->forceFill([
                'access_token_enc' => $data['access_token'],
                'refresh_token_enc' => $data['refresh_token'] ?? $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'client_endpoint' => $data['client_endpoint'] ?? $fresh->client_endpoint,
                'server_endpoint' => $data['server_endpoint'] ?? $fresh->server_endpoint,
            ])->save();

            return $fresh;
        });
    }

    private function buildMethodUrl(Tenant $tenant, string $method): string
    {
        $base = rtrim($tenant->client_endpoint ?: ('https://'.$tenant->domain.'/rest'), '/');
        if (! str_ends_with($base, '/rest')) {
            // Preserve custom rest base if Bitrix provided client_endpoint directly.
        }

        return $base.'/'.ltrim($method, '/').'.json';
    }

    private function captureOperatingMetric(array $data): void
    {
        if (isset($data['time']['operating'])) {
            $op = (float) $data['time']['operating'];
            $this->maxOperating = $this->maxOperating === null ? $op : max($this->maxOperating, $op);
        }
    }

    private function respectLeakyBucket(Tenant $tenant): void
    {
        // Only pace when real interval enforcement is desired (skipped inside Http::fake unit/feature tests).
        if (app()->runningUnitTests()) {
            return;
        }

        $intervalMicros = $tenant->isEnterprise() ? 220_000 : 520_000;
        $key = "b24_last_call_{$tenant->member_id}";
        $nowMicros = (int) (microtime(true) * 1_000_000);
        $lastMicros = (int) Cache::get($key, 0);

        if ($lastMicros > 0 && ($nowMicros - $lastMicros) < $intervalMicros) {
            usleep($intervalMicros - ($nowMicros - $lastMicros));
        }

        Cache::put($key, (int) (microtime(true) * 1_000_000), 10);
    }
}
