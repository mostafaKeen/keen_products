<?php

namespace App\Services\Bitrix24;

use Closure;

class BitrixDomainGuard
{
    private const RESERVED_BITRIX_SUBDOMAINS = [
        'www',
        'oauth',
        'api',
        'helpdesk',
        'training',
        'dev',
        'billing',
        'partners',
        'static',
        'cdn',
    ];

    private ?Closure $ipResolver = null;

    public function resolveIpsUsing(?Closure $resolver): void
    {
        $this->ipResolver = $resolver;
    }

    public function isSafeDomain(string $domain): bool
    {
        return $this->resolveSafeIps($domain) !== null;
    }

    public function isBitrixCloudDomain(string $domain): bool
    {
        $hostPort = $this->parseSafeHostPort($domain);
        if ($hostPort === null) {
            return false;
        }

        $host = $hostPort['host'];
        if (! preg_match(
            '/^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)\.bitrix24\.(?:com|eu|de|ru|ua|kz|by|pl|fr|it|es|in|jp|vn|id|cn|uk|mx|co|nl|be|ch|at|se|no|fi|dk|pt|ro|cz|com\.br|com\.tr|com\.co|com\.ua|com\.au)$/',
            $host,
            $matches
        )) {
            return false;
        }

        return ! in_array($matches[1], self::RESERVED_BITRIX_SUBDOMAINS, true);
    }

    public function isAllowedInstallDomain(string $domain): bool
    {
        $hostPort = $this->parseSafeHostPort($domain);
        if ($hostPort === null || ! $this->isSafeDomain($domain)) {
            return false;
        }

        if ($this->isBitrixCloudDomain($domain)) {
            return true;
        }

        if ((bool) config('services.bitrix24.allow_custom_domains', false)) {
            return true;
        }

        return $this->matchesConfiguredAllowedDomain($hostPort['host']);
    }

    public function isSafeHttpsUrl(string $url): bool
    {
        $hostPort = $this->extractSafeHttpsHostPort($url);
        if ($hostPort === null) {
            return false;
        }

        return $this->resolveSafeIps($this->formatHostPort($hostPort)) !== null;
    }

    public function isTrustedOauthUrl(string $url, ?string $portalDomain = null): bool
    {
        $hostPort = $this->extractSafeHttpsHostPort($url);
        if ($hostPort === null || $this->resolveSafeIps($this->formatHostPort($hostPort)) === null) {
            return false;
        }

        $host = $hostPort['host'];
        $allowedHosts = array_values(array_filter(array_map(
            static fn ($item): string => strtolower(trim((string) $item)),
            (array) config('services.bitrix24.oauth_allowed_hosts', ['oauth.bitrix.info', 'oauth.bitrix24.tech'])
        )));

        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        if ($portalDomain !== null) {
            $portalHostPort = $this->parseSafeHostPort($portalDomain);
            if ($portalHostPort !== null && $portalHostPort['host'] === $host && $this->isAllowedInstallDomain($portalDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve and verify public DNS IPs for the domain.
     *
     * @return list<string>|null Null when the domain is syntactically unsafe, resolves to a non-public IP,
     *                           or fails DNS resolution (outside unit tests without an explicit stub).
     */
    public function resolveSafeIps(string $domain): ?array
    {
        $hostPort = $this->parseSafeHostPort($domain);
        if ($hostPort === null) {
            return null;
        }

        $ips = $this->resolveHostIps($hostPort['host']);
        if ($ips === []) {
            // Outside unit tests (or when a test injects an explicit DNS stub), fail closed on unresolved domains.
            return (app()->runningUnitTests() && $this->ipResolver === null) ? [] : null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        // Prefer IPv4 first when both A and AAAA exist while retaining all validated public IPs.
        usort($ips, static fn (string $a, string $b): int => (str_contains($a, ':') ? 1 : 0) <=> (str_contains($b, ':') ? 1 : 0));

        return $ips;
    }

    /**
     * Resolve DNS once and return HTTP client options with CURLOPT_RESOLVE pinned to the verified public IP(s),
     * preventing DNS-rebinding TOCTOU between validation and TCP connection.
     *
     * @return array<string, mixed>|null Null when the URL fails SSRF/DNS safety checks.
     */
    public function pinnedHttpOptions(string $url): ?array
    {
        $hostPort = $this->extractSafeHttpsHostPort($url);
        if ($hostPort === null) {
            return null;
        }

        $ips = $this->resolveSafeIps($this->formatHostPort($hostPort));
        if ($ips === null) {
            return null;
        }

        $options = ['allow_redirects' => false];

        if ($ips !== [] && \defined('CURLOPT_RESOLVE')) {
            $port = $hostPort['port'] ?? 443;
            $formatted = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip,
                $ips
            );
            $options['curl'] = [
                \CURLOPT_RESOLVE => ["{$hostPort['host']}:{$port}:".implode(',', $formatted)],
            ];
        }

        return $options;
    }

    /**
     * @return array{host: string, port: ?int}|null
     */
    private function parseSafeHostPort(string $domain): ?array
    {
        $domain = trim($domain);
        if (
            $domain === ''
            || str_contains($domain, '..')
            || str_contains($domain, '@')
            || str_contains($domain, '/')
            || str_contains($domain, '\\')
            || str_contains($domain, '?')
            || str_contains($domain, '#')
            || preg_match('/\s/', $domain)
        ) {
            return null;
        }

        $parts = explode(':', $domain);
        if (count($parts) > 2) {
            return null;
        }

        $port = null;
        if (isset($parts[1])) {
            if ($parts[1] !== '443') {
                return null;
            }
            $port = 443;
        }

        $host = strtolower($parts[0]);
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.arpa')
            || str_ends_with($host, '.lan')
            || str_ends_with($host, '.home')
        ) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,63}$/', $host)) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    /**
     * @return array{host: string, port: ?int}|null
     */
    private function extractSafeHttpsHostPort(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            return null;
        }

        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (string) $parts['port'] : null;

        return $this->parseSafeHostPort($port !== null ? "{$host}:{$port}" : $host);
    }

    /**
     * @param  array{host: string, port: ?int}  $hostPort
     */
    private function formatHostPort(array $hostPort): string
    {
        return $hostPort['port'] !== null ? "{$hostPort['host']}:{$hostPort['port']}" : $hostPort['host'];
    }

    private function matchesConfiguredAllowedDomain(string $host): bool
    {
        $raw = (string) config('services.bitrix24.allowed_domains', '');
        $entries = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 2);
                if ($suffix !== '' && str_contains($suffix, '.') && str_ends_with($host, '.'.$suffix)) {
                    return true;
                }
            } elseif ($entry === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if ($this->ipResolver !== null) {
            return array_values(array_filter(array_map('strval', (array) ($this->ipResolver)($host))));
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                } elseif (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);

            // Block RFC 6598 Carrier-Grade NAT (100.64.0.0/10) and RFC 2544 benchmarking (198.18.0.0/15).
            if (($b0 === 100 && ($b1 & 0xC0) === 64) || ($b0 === 198 && ($b1 & 0xFE) === 18)) {
                return false;
            }

            return true;
        }

        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            // Unwrap IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), and NAT64 well-known prefixes.
            if (
                $prefix === str_repeat("\x00", 10)."\xff\xff"
                || $prefix === str_repeat("\x00", 12)
                || str_starts_with($packed, "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00")
                || str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01")
            ) {
                $embeddedIpv4 = @inet_ntop(substr($packed, 12));

                return is_string($embeddedIpv4) && $this->isPublicIp($embeddedIpv4);
            }

            // Unwrap 6to4 (2002::/16) embedded IPv4 bytes 2..5.
            if (substr($packed, 0, 2) === "\x20\x02") {
                $embeddedIpv4 = @inet_ntop(substr($packed, 2, 4));

                return is_string($embeddedIpv4) && $this->isPublicIp($embeddedIpv4);
            }
        }

        return true;
    }
}
