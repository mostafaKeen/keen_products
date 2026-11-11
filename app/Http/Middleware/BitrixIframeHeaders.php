<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BitrixIframeHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Allow Bitrix24 cloud and self-hosted portals to render iframe placements.
        $response->headers->remove('X-Frame-Options');

        $ancestors = [
            "'self'",
            'https://*.bitrix24.com',
            'https://*.bitrix24.eu',
            'https://*.bitrix24.de',
            'https://*.bitrix24.ru',
            'https://*.bitrix24.ua',
            'https://*.bitrix24.kz',
            'https://*.bitrix24.by',
            'https://*.bitrix24.pl',
            'https://*.bitrix24.fr',
            'https://*.bitrix24.it',
            'https://*.bitrix24.es',
            'https://*.bitrix24.com.br',
            'https://*.bitrix24.in',
            'https://*.bitrix24.jp',
            'https://*.bitrix24.vn',
            'https://*.bitrix24.id',
            'https://*.bitrix24.com.tr',
            'https://*.bitrix24.cn',
        ];

        $domain = trim((string) $request->input('DOMAIN', ''));
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(:\d+)?$/i', $domain)) {
            $ancestors[] = 'https://'.$domain;
        }

        $configuredAncestors = trim((string) config('services.bitrix24.allowed_frame_ancestors', ''));
        if ($configuredAncestors !== '') {
            $tokens = preg_split('/[\s,]+/', $configuredAncestors, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $origin) {
                $clean = trim($origin);
                if ($clean !== '' && ! preg_match('/[;\r\n]/', $clean)) {
                    $ancestors[] = $clean;
                }
            }
        }

        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', array_unique($ancestors)));

        return $response;
    }
}
