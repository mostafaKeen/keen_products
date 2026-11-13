<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminSystemHealthController;
use App\Http\Controllers\Admin\AdminTenantController;
use App\Http\Controllers\Bitrix\BitrixEntrypointController;
use App\Http\Controllers\Bitrix\BitrixPortalApiController;
use App\Http\Controllers\Bitrix\BitrixWidgetApiController;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$bitrixAppUrls = fn () => [
    'install' => url('/bitrix/install'),
    'app' => url('/bitrix/app'),
    'events' => url('/bitrix/events'),
    'eula' => url('/eula'),
    'privacy' => url('/privacy'),
];

Route::middleware('throttle:public-web')->group(function () use ($bitrixAppUrls) {
    Route::get('/', fn () => Inertia::render('welcome', [
        'plans' => Plan::cachedPublicPlans(),
        'app_urls' => $bitrixAppUrls(),
    ]))->name('home');

    Route::get('/features', fn () => Inertia::render('landing/features', [
        'app_urls' => $bitrixAppUrls(),
    ]))->name('landing.features');

    Route::get('/pricing', fn () => Inertia::render('landing/pricing', [
        'plans' => Plan::cachedPublicPlans(),
    ]))->name('landing.pricing');

    Route::get('/eula', fn () => Inertia::render('landing/eula', [
        'app_urls' => $bitrixAppUrls(),
    ]))->name('landing.eula');

    Route::get('/privacy', fn () => Inertia::render('landing/privacy', [
        'app_urls' => $bitrixAppUrls(),
    ]))->name('landing.privacy');

    Route::get('/terms', fn () => redirect()->route('landing.eula'));
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        if (request()->user()?->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('dashboard');
    })->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Super Admin Dashboard & Subscription Management Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('/tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');
        Route::post('/tenants/{tenant}/probe', [AdminTenantController::class, 'probe'])->name('tenants.probe');
        Route::patch('/tenants/{tenant}/status', [AdminTenantController::class, 'updateStatus'])->name('tenants.status');
        Route::post('/tenants/{tenant}/fetch-bitrix-users', [AdminTenantController::class, 'fetchBitrixUsers'])->name('tenants.fetch-users');
        Route::put('/tenants/{tenant}/allowed-users', [AdminTenantController::class, 'updateAllowedUsers'])->name('tenants.allowed-users');

        Route::get('/plans', [AdminPlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [AdminPlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');

        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::put('/tenants/{tenant}/subscription', [AdminSubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::post('/tenants/{tenant}/subscription/activate', [AdminSubscriptionController::class, 'activate'])->name('subscriptions.activate');
        Route::post('/tenants/{tenant}/subscription/deactivate', [AdminSubscriptionController::class, 'deactivate'])->name('subscriptions.deactivate');
        Route::post('/tenants/{tenant}/payments', [AdminSubscriptionController::class, 'recordPayment'])->name('subscriptions.payments.store');

        Route::get('/system-health', [AdminSystemHealthController::class, 'index'])->name('system-health.index');
        Route::post('/system-health/failed-jobs/{uuid}/retry', [AdminSystemHealthController::class, 'retryFailedJob'])->name('system-health.retry');
        Route::post('/system-health/prune-logs', [AdminSystemHealthController::class, 'pruneOldLogs'])->name('system-health.prune');
    });

/*
|--------------------------------------------------------------------------
| Bitrix24 Marketplace Entrypoints & Event Webhooks (Iframe & Server-to-Server)
|--------------------------------------------------------------------------
*/
Route::middleware(['bitrix.iframe'])
    ->prefix('bitrix')
    ->name('bitrix.')
    ->group(function () {
        Route::match(['get', 'post'], '/install', [BitrixEntrypointController::class, 'install'])
            ->middleware('throttle:bitrix-install')
            ->name('install');
        Route::match(['get', 'post'], '/app', [BitrixEntrypointController::class, 'app'])
            ->middleware('throttle:bitrix-iframe')
            ->name('app');
        Route::match(['get', 'post'], '/widget', [BitrixEntrypointController::class, 'widget'])
            ->middleware('throttle:bitrix-iframe')
            ->name('widget');
    });

Route::post('/bitrix/events', [BitrixEntrypointController::class, 'events'])
    ->middleware('throttle:bitrix-events')
    ->name('bitrix.events');

/*
|--------------------------------------------------------------------------
| Bitrix24 Iframe JSON APIs (Signed Stateless JWT Bearer Auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:bitrix-api-ip', 'bitrix.jwt:app,install'])
    ->prefix('api/bitrix/portal')
    ->name('api.bitrix.portal.')
    ->group(function () {
        Route::post('/complete-install', [BitrixPortalApiController::class, 'completeInstall'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('complete-install');
        Route::post('/scan-schema', [BitrixPortalApiController::class, 'scanSchema'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('scan-schema');
        Route::post('/provision-auto', [BitrixPortalApiController::class, 'provisionAuto'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('provision-auto');
        Route::post('/save-mappings', [BitrixPortalApiController::class, 'saveMappings'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('save-mappings');
        Route::post('/sync-bindings', [BitrixPortalApiController::class, 'syncBindings'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('sync-bindings');
        Route::post('/backfill', [BitrixPortalApiController::class, 'runBackfill'])
            ->middleware('throttle:bitrix-portal-heavy')
            ->name('backfill');
        Route::get('/logs', [BitrixPortalApiController::class, 'logs'])->name('logs');
        Route::post('/request-upgrade', [BitrixPortalApiController::class, 'requestUpgrade'])->name('request-upgrade');
    });

Route::middleware(['throttle:bitrix-api-ip', 'bitrix.jwt:widget', 'throttle:bitrix-widget'])
    ->prefix('api/bitrix/widget')
    ->name('api.bitrix.widget.')
    ->group(function () {
        Route::post('/load', [BitrixWidgetApiController::class, 'load'])->name('load');
        Route::post('/catalog-search', [BitrixWidgetApiController::class, 'catalogSearch'])->name('catalog-search');
        Route::post('/catalog-product', [BitrixWidgetApiController::class, 'upsertCatalogProduct'])->name('catalog-product');
        Route::post('/sync', [BitrixWidgetApiController::class, 'sync'])->name('sync');
        Route::post('/clear', [BitrixWidgetApiController::class, 'clear'])->name('clear');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
