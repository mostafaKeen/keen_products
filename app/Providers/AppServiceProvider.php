<?php

namespace App\Providers;

use App\Services\Bitrix24\BitrixDomainGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BitrixDomainGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-web', fn (Request $request) => [
            Limit::perMinute(120)->by($request->ip()),
        ]);

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        RateLimiter::for('auth-password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(15)->by($request->ip()),
        ]);

        RateLimiter::for('auth-password-confirm', fn (Request $request) => [
            Limit::perMinute(6)->by(($request->user()?->id ?: 'guest').'|'.$request->ip()),
        ]);

        RateLimiter::for('bitrix-install', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('bitrix-iframe', fn (Request $request) => [
            Limit::perMinute(60)->by($request->ip()),
        ]);

        RateLimiter::for('bitrix-events', function (Request $request) {
            $memberId = trim((string) data_get($request->all(), 'auth.member_id', ''));

            return [
                Limit::perMinute(240)->by($request->ip()),
                Limit::perMinute(120)->by(($memberId !== '' ? $memberId : 'unknown').'|'.$request->ip()),
            ];
        });

        RateLimiter::for('bitrix-api-ip', fn (Request $request) => [
            Limit::perMinute(120)->by($request->ip()),
        ]);

        RateLimiter::for('bitrix-portal-heavy', function (Request $request) {
            $tenantId = $request->attributes->get('bitrix_tenant')?->id;

            return [
                Limit::perMinute(10)->by('portal-heavy:'.($tenantId ?: $request->ip())),
            ];
        });

        RateLimiter::for('bitrix-widget', function (Request $request) {
            $tenantId = $request->attributes->get('bitrix_tenant')?->id;
            $claims = (array) $request->attributes->get('bitrix_claims', []);
            $actor = $claims['b24_user_id'] ?? $request->ip();

            return [
                Limit::perMinute(60)->by('widget:'.($tenantId ?: 'none').'|'.$actor),
            ];
        });
    }
}
