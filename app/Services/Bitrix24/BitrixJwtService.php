<?php

namespace App\Services\Bitrix24;

use App\Models\Tenant;
use Illuminate\Validation\UnauthorizedException;

class BitrixJwtService
{
    public function issueForTenant(
        Tenant $tenant,
        string $scopeMode,
        ?int $b24UserId = null,
        bool $isAdmin = false,
        array $extraClaims = [],
    ): string {
        $now = time();
        $ttl = max(300, (int) config('services.bitrix24.jwt_ttl_seconds', 7200));

        $payload = array_merge($extraClaims, [
            'iss' => config('app.url'),
            'tenant_id' => $tenant->id,
            'member_id' => $tenant->member_id,
            'domain' => $tenant->domain,
            'scope_mode' => $scopeMode,
            'b24_user_id' => $b24UserId,
            'is_admin' => $isAdmin,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $headerEncoded = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->sign("{$headerEncoded}.{$payloadEncoded}");

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new UnauthorizedException('Malformed iframe session token.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $expectedSig = $this->sign("{$headerB64}.{$payloadB64}");

        if (! hash_equals($expectedSig, $sigB64)) {
            throw new UnauthorizedException('Invalid iframe session signature.');
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (! is_array($payload)) {
            throw new UnauthorizedException('Invalid iframe session payload.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new UnauthorizedException('Iframe session token expired. Reload the tab.');
        }

        return $payload;
    }

    private function sign(string $signingInput): string
    {
        $secret = (string) (config('services.bitrix24.jwt_secret') ?: config('app.key'));
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if ($decoded !== false) {
                $secret = $decoded;
            }
        }

        return $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}

