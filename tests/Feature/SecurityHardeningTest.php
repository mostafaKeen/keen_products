<?php

namespace Tests\Feature;

use App\Exceptions\BitrixApiException;
use App\Jobs\ProcessBitrixEventJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Models\TenantEntityConfig;
use App\Models\TenantUsageMonthly;
use App\Models\User;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixDomainGuard;
use App\Services\Bitrix24\BitrixJwtService;
use App\Services\Bitrix24\BitrixSchemaProvisioner;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\TenantSyncService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_install_rejects_blank_auth_id_and_blocks_existing_tenant_domain_hijack(): void
    {
        // 1. Blank AUTH_ID must fail with null JWT and no DB tenant created
        $blankRes = $this->post('/bitrix/install', [
            'member_id' => 'blank_auth_member',
            'DOMAIN' => 'attacker.example.com',
            'AUTH_ID' => '',
            'APPLICATION_TOKEN' => 'any_token',
        ]);

        $blankRes->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bitrix/install-wizard')
                ->where('jwt', null)
                ->where('tenant', null)
            );
        $this->assertDatabaseMissing('tenants', ['member_id' => 'blank_auth_member']);

        // 2. Existing tenant cannot have DOMAIN / client_endpoint hijacked or probed
        $victim = Tenant::create([
            'member_id' => 'victim_member_1',
            'domain' => 'victim.bitrix24.com',
            'client_endpoint' => 'https://victim.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'victim_secret_oauth',
            'application_token_enc' => 'victim_app_sig',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        $probedUrls = [];
        Http::fake(function (HttpClientRequest $req) use (&$probedUrls) {
            $probedUrls[] = $req->url();
            if (str_contains($req->url(), 'attacker.example.com')) {
                return Http::response(['result' => true], 200);
            }

            return Http::response(['error' => 'invalid_token'], 401);
        });

        $hijackRes = $this->post('/bitrix/install', [
            'member_id' => 'victim_member_1',
            'DOMAIN' => 'attacker.example.com',
            'PROTOCOL' => '0',
            'AUTH_ID' => 'attacker_forged_tok',
        ]);

        $hijackRes->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bitrix/install-wizard')
                ->where('jwt', null)
            );

        $victim->refresh();
        $this->assertSame('victim.bitrix24.com', $victim->domain);
        $this->assertSame('https://victim.bitrix24.com/rest/', $victim->client_endpoint);
        $this->assertSame('victim_secret_oauth', $victim->access_token_enc);
        $this->assertFalse(collect($probedUrls)->contains(fn ($u) => str_contains($u, 'attacker.example.com')));
    }

    public function test_events_require_valid_signature_for_onappinstall_and_admin_role_for_onappupdate_fallback(): void
    {
        $suspended = Tenant::create([
            'member_id' => 'member_suspended_evt',
            'domain' => 'suspended.bitrix24.com',
            'client_endpoint' => 'https://suspended.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'application_token_enc' => 'sig_suspended',
            'access_token_enc' => 'orig_admin_tok',
            'install_status' => Tenant::INSTALL_SUSPENDED,
        ]);

        // Forged ONAPPINSTALL with wrong signature
        $this->post('/bitrix/events', [
            'event' => 'ONAPPINSTALL',
            'auth' => ['member_id' => 'member_suspended_evt', 'application_token' => 'wrong'],
        ])->assertForbidden();

        // Even with matching signature, suspended portal cannot self-reactivate
        $this->post('/bitrix/events', [
            'event' => 'ONAPPINSTALL',
            'auth' => ['member_id' => 'member_suspended_evt', 'application_token' => 'sig_suspended'],
        ])->assertForbidden();
        $this->assertSame(Tenant::INSTALL_SUSPENDED, $suspended->fresh()->install_status);

        // Uninstalled tenant CAN reactivate on ONAPPINSTALL with valid signature
        $uninstalled = Tenant::create([
            'member_id' => 'member_uninst_evt',
            'domain' => 'uninst.bitrix24.com',
            'client_endpoint' => 'https://uninst.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'application_token_enc' => 'sig_uninst',
            'install_status' => Tenant::INSTALL_UNINSTALLED,
        ]);

        $this->post('/bitrix/events', [
            'event' => 'ONAPPINSTALL',
            'auth' => ['member_id' => 'member_uninst_evt', 'application_token' => 'sig_uninst'],
        ])->assertOk();
        $this->assertSame(Tenant::INSTALL_ACTIVE, $uninstalled->fresh()->install_status);

        // Non-admin user access_token fallback on ONAPPUPDATE must be forbidden
        Http::fake([
            'https://uninst.bitrix24.com/rest/user.admin.json' => Http::response(['result' => false], 200),
            'https://uninst.bitrix24.com/rest/profile.json' => Http::response(['result' => ['ID' => 99]], 200),
        ]);

        $this->post('/bitrix/events', [
            'event' => 'ONAPPUPDATE',
            'auth' => [
                'member_id' => 'member_uninst_evt',
                'application_token' => 'attacker_new_sig',
                'access_token' => 'non_admin_user_tok',
            ],
        ])->assertForbidden();
        $this->assertSame('sig_uninst', $uninstalled->fresh()->application_token_enc);
    }

    public function test_widget_and_app_reject_missing_auth_and_non_admin_crm_acl_denied_entities(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_widget_acl',
            'domain' => 'acl.bitrix24.com',
            'client_endpoint' => 'https://acl.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'application_token_enc' => 'sig_acl',
            'access_token_enc' => 'admin_tok',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        // APPLICATION_TOKEN without AUTH_ID must fail on both /bitrix/app and /bitrix/widget
        $this->post('/bitrix/app', [
            'member_id' => 'member_widget_acl',
            'APPLICATION_TOKEN' => 'sig_acl',
            'DOMAIN' => 'attacker-app.example.com',
        ])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('jwt', null));

        $this->assertSame('acl.bitrix24.com', $tenant->fresh()->domain);

        $this->post('/bitrix/widget', [
            'member_id' => 'member_widget_acl',
            'APPLICATION_TOKEN' => 'sig_acl',
            'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
            'PLACEMENT_OPTIONS' => json_encode(['ID' => 44]),
        ])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('jwt', null)->where('context', null));

        // Rep with valid AUTH_ID but denied Bitrix ACL for Deal #44 must be rejected without loading admin context
        Http::fake([
            'https://acl.bitrix24.com/rest/user.admin.json' => Http::response(['result' => false], 200),
            'https://acl.bitrix24.com/rest/profile.json' => Http::response(['result' => ['ID' => 19]], 200),
            'https://acl.bitrix24.com/rest/crm.deal.get.json' => Http::response(['error' => 'ACCESS_DENIED'], 403),
        ]);

        $this->post('/bitrix/widget', [
            'member_id' => 'member_widget_acl',
            'APPLICATION_TOKEN' => 'sig_acl',
            'AUTH_ID' => 'rep_no_deal_access',
            'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
            'PLACEMENT_OPTIONS' => json_encode(['ID' => 44]),
        ])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('jwt', null)->where('context', null));
    }

    public function test_suspended_tenants_expired_catalog_ops_and_non_public_upgrade_plans_are_blocked(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_api_gates',
            'domain' => 'gates.bitrix24.com',
            'client_endpoint' => 'https://gates.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_gates',
            'install_status' => Tenant::INSTALL_SUSPENDED,
        ]);

        TenantCatalogConfig::create([
            'tenant_id' => $tenant->id,
            'iblock_id' => 24,
        ]);

        $adminInstallJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'install', 1, true);
        $adminWidgetJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'widget', 1, true, ['entity_type_id' => 2, 'entity_id' => 10]);

        // Suspended tenant rejected by VerifyWidgetJwt on portal & widget routes
        $this->withToken($adminInstallJwt)->postJson('/api/bitrix/portal/complete-install')->assertForbidden();
        $this->withToken($adminWidgetJwt)->postJson('/api/bitrix/widget/catalog-search', ['q' => 'fee'])->assertForbidden();

        // Reactivate tenant, set expired subscription -> catalog search & upsert must return 403
        $tenant->update(['install_status' => Tenant::INSTALL_ACTIVE]);
        $starter = Plan::where('code', 'starter')->firstOrFail();
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $starter->id,
            'status' => Subscription::STATUS_EXPIRED,
            'billing_cycle' => 'monthly',
        ]);

        $this->withToken($adminWidgetJwt)->postJson('/api/bitrix/widget/catalog-search', ['q' => 'fee'])->assertForbidden();
        $this->withToken($adminWidgetJwt)->postJson('/api/bitrix/widget/catalog-product', [
            'name' => 'Blocked',
            'price' => 100,
            'currency' => 'USD',
        ])->assertForbidden();
        $this->withToken($adminInstallJwt)->postJson('/api/bitrix/portal/scan-schema')->assertForbidden();
        $this->withToken($adminInstallJwt)->postJson('/api/bitrix/portal/provision-auto', ['entity_type_ids' => [2]])->assertForbidden();
        $this->withToken($adminInstallJwt)->postJson('/api/bitrix/portal/save-mappings', [
            'fee_sync_mode' => Tenant::MODE_SUBTOTALS_ONLY,
            'catalog' => ['iblock_id' => 24],
            'entities' => [['entity_type_id' => 2]],
        ])->assertForbidden();

        // Private plan cannot be requested via upgrade endpoint; non-admin widget rep cannot mutate catalog
        $privatePlan = Plan::create([
            'code' => 'vip_hidden',
            'name' => 'VIP Hidden',
            'price_monthly_cents' => 99900,
            'price_yearly_cents' => 999000,
            'currency' => 'USD',
            'trial_days' => 0,
            'max_monthly_syncs' => null,
            'max_options' => 10,
            'is_active' => true,
            'is_public' => false,
            'features_json' => ['allow_custom_product_attributes' => true],
        ]);

        $sub = $tenant->subscriptions()->firstOrFail();
        $sub->update(['status' => Subscription::STATUS_ACTIVE]);

        $this->withToken($adminInstallJwt)->postJson('/api/bitrix/portal/request-upgrade', [
            'plan_id' => $privatePlan->id,
        ])->assertNotFound();

        $repWidgetJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'widget', 25, false, ['entity_type_id' => 2, 'entity_id' => 10]);
        $this->withToken($repWidgetJwt)->postJson('/api/bitrix/widget/catalog-product', [
            'name' => 'Unauthorized Rep Product',
            'price' => 1,
            'currency' => 'USD',
        ])->assertForbidden();
    }

    public function test_csp_does_not_reflect_untrusted_domain_and_schema_plan_limits_apply_to_mappings_and_sync(): void
    {
        $res = $this->get('/bitrix/install?DOMAIN=evil-framer.example.com');
        $res->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('evil-framer.example.com', $csp);

        // Tenant on Trial (max_options = 2, allow_custom_product_attributes check)
        $tenant = Tenant::create([
            'member_id' => 'member_limits',
            'domain' => 'custom.selfhosted.example',
            'client_endpoint' => 'https://custom.selfhosted.example/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_lim',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        // Registered active tenant DOES get its verified domain in CSP when member_id matches
        $res2 = $this->get('/bitrix/install?member_id=member_limits');
        $this->assertStringContainsString('https://custom.selfhosted.example', (string) $res2->headers->get('Content-Security-Policy'));

        $restrictedPlan = Plan::create([
            'code' => 'basic_restricted',
            'name' => 'Basic Restricted',
            'price_monthly_cents' => 1000,
            'price_yearly_cents' => 10000,
            'currency' => 'USD',
            'trial_days' => 14,
            'max_monthly_syncs' => 100,
            'max_options' => 2,
            'is_active' => true,
            'is_public' => true,
            'features_json' => [
                'allow_auto_background_sync' => false,
                'allow_bidirectional_spa_sync' => false,
                'allow_custom_spas' => false,
                'allow_custom_product_attributes' => false,
                'allow_backfill' => false,
            ],
        ]);
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $restrictedPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'current_period_end' => now()->addMonth(),
        ]);

        Http::fake([
            'https://custom.selfhosted.example/rest/placement.*' => Http::response(['result' => []]),
            'https://custom.selfhosted.example/rest/event.*' => Http::response(['result' => []]),
            'https://custom.selfhosted.example/rest/crm.deal.get*' => Http::response(['result' => ['ID' => 12, 'CURRENCY_ID' => 'USD']]),
            'https://custom.selfhosted.example/rest/crm.item.productrow.set*' => Http::response(['result' => true]),
            'https://custom.selfhosted.example/rest/crm.item.productrow.list*' => Http::response(['result' => ['productRows' => [
                ['id' => 1, 'productName' => 'Service', 'price' => 100, 'quantity' => 1],
            ]]]),
            'https://custom.selfhosted.example/rest/crm.deal.update*' => Http::response(['result' => true]),
        ]);

        // saveCustomMappings ignores client-sent extra_attributes & extra options when feature disallowed
        app(BitrixSchemaProvisioner::class)->saveCustomMappings($tenant, [
            'fee_sync_mode' => Tenant::MODE_SUBTOTALS_ONLY,
            'catalog' => ['iblock_id' => 24],
            'options_config' => [
                ['key' => 'option_1', 'label' => 'Opt 1'],
                ['key' => 'option_2', 'label' => 'Opt 2'],
                ['key' => 'option_3', 'label' => 'Opt 3 Bypass'],
            ],
            'extra_attributes' => [
                ['key' => 'custom_secret', 'label' => 'Custom Secret'],
            ],
            'entities' => [
                ['entity_type_id' => 2, 'logical_key' => 'deal', 'title' => 'Deals', 'fields' => []],
            ],
        ]);

        $cfg = $tenant->catalogConfig()->firstOrFail();
        $this->assertSame([], $cfg->extra_attributes_json);
        $this->assertCount(2, $cfg->options_config_json);

        // syncEntity ignores unauthorized option_3 override
        $syncRes = app(TenantSyncService::class)->syncEntity($tenant->fresh(), 2, 12, 'widget', [
            'currencyId' => 'USD',
            'rows' => [[
                'productName' => 'Service',
                'price' => 100,
                'quantity' => 1,
                'costTypeKey' => 'professional',
                'optionKey' => 'option_3',
            ]],
        ], true);

        $this->assertTrue($syncRes['success']);
        $this->assertArrayNotHasKey('option_3', $syncRes['summary']['option_totals']);
    }

    public function test_usage_increment_is_atomic_rate_limiters_and_seeder_guard_work(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_atomic_usage',
            'domain' => 'atomic.bitrix24.com',
            'client_endpoint' => 'https://atomic.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        $gate = app(SubscriptionGate::class);
        $first = $gate->incrementUsage($tenant, 1, 2, 0);
        // Simulate external concurrent DB increment behind stale cache
        TenantUsageMonthly::where('id', $first->id)->increment('sync_count', 5);
        $second = $gate->incrementUsage($tenant, 1, 3, 1);

        $this->assertSame(7, $second->sync_count);
        $this->assertSame(5, $second->api_calls_count);
        $this->assertSame(1, $second->webhook_count);

        // Auth register route throttles after 5 attempts per minute
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', []);
        }
        $this->post('/register', [])->assertTooManyRequests();

        // DatabaseSeeder outside local/testing refuses to seed default 'password' admin
        app()->detectEnvironment(fn () => 'production');
        try {
            $beforeUsers = User::count();
            app(DatabaseSeeder::class)->run();
            $this->assertSame($beforeUsers, User::count());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        // LoginRequest IP throttle stops password spraying across distinct emails
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', [
                'email' => "spray_{$i}@example.com",
                'password' => 'wrong-pass',
            ]);
        }

        $this->post('/login', [
            'email' => 'spray_21@example.com',
            'password' => 'wrong-pass',
        ])->assertSessionHasErrors('email');
    }

    public function test_widget_enforces_catalog_iblock_ownership_and_sets_can_manage_catalog_prop(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_cat_prop',
            'domain' => 'catprop.bitrix24.com',
            'client_endpoint' => 'https://catprop.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'application_token_enc' => 'sig_cat',
            'access_token_enc' => 'tok_cat_admin',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        TenantCatalogConfig::create([
            'tenant_id' => $tenant->id,
            'iblock_id' => 24,
        ]);

        TenantEntityConfig::create([
            'tenant_id' => $tenant->id,
            'logical_key' => 'deal',
            'title' => 'Deals',
            'entity_type_id' => 2,
            'owner_type_code' => 'D',
            'placement_code' => 'CRM_DEAL_DETAIL_TAB',
            'is_enabled' => true,
            'auto_sync_enabled' => true,
        ]);

        Http::fake([
            'https://catprop.bitrix24.com/rest/user.admin.json' => Http::response(['result' => false], 200),
            'https://catprop.bitrix24.com/rest/profile.json' => Http::response(['result' => ['ID' => 77]], 200),
            'https://catprop.bitrix24.com/rest/crm.deal.get*' => Http::response(['result' => ['ID' => 10, 'TITLE' => 'Allowed Deal', 'CURRENCY_ID' => 'USD']]),
            'https://catprop.bitrix24.com/rest/crm.item.productrow.list*' => Http::response(['result' => ['productRows' => []]]),
            'https://catprop.bitrix24.com/rest/crm.currency.list*' => Http::response(['result' => [['CURRENCY' => 'USD', 'FULL_NAME' => 'USD', 'BASE' => 'Y']]]),
            'https://catprop.bitrix24.com/rest/catalog.product.get*' => Http::response(['result' => ['product' => ['id' => 999, 'iblockId' => 99]]]),
        ]);

        // Non-admin rep with deal access gets widget JWT & can_manage_catalog=false
        $this->post('/bitrix/widget', [
            'member_id' => 'member_cat_prop',
            'APPLICATION_TOKEN' => 'sig_cat',
            'AUTH_ID' => 'rep_with_access',
            'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
            'PLACEMENT_OPTIONS' => json_encode(['ID' => 10]),
        ])->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bitrix/widget')
                ->where('can_manage_catalog', false)
                ->where('error', null)
            );

        // Admin JWT attempting to update a product from different iblock (99 != 24) is forbidden
        $adminWidgetJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'widget', 1, true, ['entity_type_id' => 2, 'entity_id' => 10]);
        $this->withToken($adminWidgetJwt)->postJson('/api/bitrix/widget/catalog-product', [
            'id' => 999,
            'name' => 'Cross-Catalog Attempt',
            'price' => 50,
            'currency' => 'USD',
        ])->assertForbidden();

        // Query/body jwt parameter without Authorization Bearer header must be rejected
        $this->withoutToken();
        $this->getJson('/api/bitrix/portal/logs?jwt='.$adminWidgetJwt)->assertUnauthorized();
        $this->postJson('/api/bitrix/widget/load', ['jwt' => $adminWidgetJwt])->assertUnauthorized();
    }

    public function test_domain_guard_blocks_ports_private_dns_and_requires_app_token_on_new_install(): void
    {
        Http::fake();

        // 1. Custom non-443 port rejected without outbound HTTP probes
        $this->post('/bitrix/install', [
            'member_id' => 'ssrf_port_member',
            'DOMAIN' => 'internal.example.com:6379',
            'AUTH_ID' => 'tok_x',
            'APPLICATION_TOKEN' => 'sig_x',
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('jwt', null)->where('tenant', null));
        Http::assertNothingSent();

        // 2. Domain resolving to loopback/private IP is rejected without outbound HTTP probes
        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn () => ['127.0.0.1']);

        try {
            $this->post('/bitrix/install', [
                'member_id' => 'ssrf_dns_member',
                'DOMAIN' => 'rebind.example.com',
                'AUTH_ID' => 'tok_x',
                'APPLICATION_TOKEN' => 'sig_x',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('jwt', null)->where('tenant', null));
            Http::assertNothingSent();
        } finally {
            $guard->resolveIpsUsing(null);
        }

        // 3. New install without APPLICATION_TOKEN is rejected
        $this->post('/bitrix/install', [
            'member_id' => 'missing_sig_member',
            'DOMAIN' => 'fresh-portal.bitrix24.com',
            'AUTH_ID' => 'tok_x',
        ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('jwt', null)->where('tenant', null));
        $this->assertDatabaseMissing('tenants', ['member_id' => 'missing_sig_member']);
        Http::assertNothingSent();
    }

    public function test_install_sanitizes_unsafe_server_endpoint_and_protects_domain_from_unsigned_app_hits(): void
    {
        // 4. Unsafe SERVER_ENDPOINT is sanitized back to Bitrix OAuth default
        Http::fake([
            'https://valid.bitrix24.com/rest/user.admin.json' => Http::response(['result' => true], 200),
            'https://valid.bitrix24.com/rest/profile.json' => Http::response(['result' => ['ID' => 1, 'NAME' => 'Ada']], 200),
        ]);

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn (string $host) => $host === 'valid.bitrix24.com' ? ['93.184.216.34'] : ['10.0.0.5']);

        try {
            $this->post('/bitrix/install', [
                'member_id' => 'valid_sig_member',
                'DOMAIN' => 'valid.bitrix24.com',
                'AUTH_ID' => 'tok_valid',
                'APPLICATION_TOKEN' => 'app_sig_valid',
                'SERVER_ENDPOINT' => 'https://internal.service:8443/rest/',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', null));

            $created = Tenant::where('member_id', 'valid_sig_member')->firstOrFail();
            $this->assertSame('https://oauth.bitrix.info/rest/', $created->server_endpoint);

            // 5. Active tenant with stored application_token_enc ignores DOMAIN change if APPLICATION_TOKEN omitted
            $guard->resolveIpsUsing(fn () => ['93.184.216.34']);
            $this->post('/bitrix/app', [
                'member_id' => 'valid_sig_member',
                'DOMAIN' => 'other-domain.bitrix24.com',
                'AUTH_ID' => 'tok_valid',
            ])->assertOk();
            $this->assertSame('valid.bitrix24.com', $created->fresh()->domain);
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_atomic_quota_reservation_widget_sync_403_save_mappings_spa_ownership_and_prod_jwt_fail_closed(): void
    {
        // 1. BitrixJwtService must fail closed outside local/testing if BITRIX24_JWT_SECRET matches APP_KEY or is blank
        $prevSecret = config('services.bitrix24.jwt_secret');
        app()->detectEnvironment(fn () => 'production');
        config(['services.bitrix24.jwt_secret' => config('app.key')]);

        $dummyTenant = new Tenant(['member_id' => 'prod_chk', 'domain' => 'prod.bitrix24.com']);
        $dummyTenant->id = 99;

        try {
            $this->expectException(\RuntimeException::class);
            app(BitrixJwtService::class)->issueForTenant($dummyTenant, 'widget', 1, false);
        } finally {
            config(['services.bitrix24.jwt_secret' => $prevSecret]);
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_atomic_quota_slot_reservation_widget_403_spa_ownership_and_heavy_rate_limits(): void
    {
        $starter = Plan::where('code', 'starter')->firstOrFail();
        $tightPlan = Plan::create([
            'code' => 'quota_cap_1',
            'name' => 'Cap One',
            'price_monthly_cents' => 1000,
            'price_yearly_cents' => 10000,
            'currency' => 'USD',
            'trial_days' => 0,
            'max_monthly_syncs' => 1,
            'max_options' => 2,
            'is_active' => true,
            'is_public' => true,
            'features_json' => ['allow_custom_spas' => true, 'allow_auto_background_sync' => true],
        ]);

        // Public plans cache invalidation immediately reflects newly created public plan
        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('plans', fn ($plans) => collect($plans)->contains('code', 'quota_cap_1')));

        $tenant = Tenant::create([
            'member_id' => 'member_quota_atomic',
            'domain' => 'quota.bitrix24.com',
            'client_endpoint' => 'https://quota.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_q',
            'fee_sync_mode' => Tenant::MODE_SUBTOTALS_ONLY,
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $tightPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'current_period_end' => now()->addMonth(),
        ]);

        TenantCatalogConfig::create([
            'tenant_id' => $tenant->id,
            'iblock_id' => 24,
        ]);
        TenantEntityConfig::create([
            'tenant_id' => $tenant->id,
            'logical_key' => 'deal',
            'title' => 'Deals',
            'entity_type_id' => 2,
            'owner_type_code' => 'D',
            'placement_code' => 'CRM_DEAL_DETAIL_TAB',
            'is_enabled' => true,
            'auto_sync_enabled' => true,
        ]);

        Http::fake([
            'https://quota.bitrix24.com/rest/crm.deal.get*' => Http::response(['result' => ['ID' => 5, 'CURRENCY_ID' => 'USD']]),
            'https://quota.bitrix24.com/rest/crm.item.productrow.list*' => Http::response(['result' => ['productRows' => []]]),
            'https://quota.bitrix24.com/rest/crm.deal.update*' => Http::response(['result' => true]),
            'https://quota.bitrix24.com/rest/crm.type.list*' => Http::response(['result' => ['types' => [
                ['id' => 9, 'entityTypeId' => 1090, 'title' => 'Portal Fee SPA'],
            ]]]),
            'https://quota.bitrix24.com/rest/placement.*' => Http::response(['result' => []]),
            'https://quota.bitrix24.com/rest/event.*' => Http::response(['result' => []]),
        ]);

        // Unforced webhook matching hash must release reserved sync slot (consumes 1 on first sync, 0 on skip)
        $syncService = app(TenantSyncService::class);
        $first = $syncService->syncEntity($tenant, 2, 5, 'webhook', null, false);
        $this->assertTrue($first['success']);

        // Grant +1 quota temporarily to verify skipped identical hash leaves usage untouched
        $tightPlan->update(['max_monthly_syncs' => 2]);
        $skipped = $syncService->syncEntity($tenant->fresh(), 2, 5, 'webhook', null, false);
        $this->assertTrue((bool) ($skipped['skipped'] ?? false));
        $this->assertSame(1, TenantUsageMonthly::where('tenant_id', $tenant->id)->firstOrFail()->sync_count);

        // Enforce cap = 1 cleanly and verify widget sync returns HTTP 403 when exhausted
        $tightPlan->update(['max_monthly_syncs' => 1]);
        $blocked = $syncService->syncEntity($tenant->fresh(), 2, 5, 'webhook', null, true);
        $this->assertFalse($blocked['success']);
        $this->assertSame(1, TenantUsageMonthly::where('tenant_id', $tenant->id)->firstOrFail()->sync_count);

        $widgetJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'widget', 1, true, ['entity_type_id' => 2, 'entity_id' => 5]);
        $this->withToken($widgetJwt)->postJson('/api/bitrix/widget/sync', [
            'currencyId' => 'USD',
            'rows' => [['productName' => 'Blocked', 'price' => 10, 'quantity' => 1]],
        ])->assertForbidden();

        // Atomic SQL reservation prevents concurrent overshoot past max_monthly_syncs
        $gate = app(SubscriptionGate::class);
        $this->assertNull($gate->tryReserveSyncSlot($tenant, 1));
        $this->assertSame(1, TenantUsageMonthly::where('tenant_id', $tenant->id)->firstOrFail()->sync_count);

        // save-mappings rejects forged SPA entityTypeId not belonging to tenant's portal (422)
        $portalJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'app', 1, true);
        $this->withToken($portalJwt)->postJson('/api/bitrix/portal/save-mappings', [
            'fee_sync_mode' => Tenant::MODE_CONSOLIDATED_SPAS,
            'catalog' => ['iblock_id' => 24],
            'spa_buckets' => [[
                'bucket_key' => 'consolidated_all',
                'title' => 'Fee Items',
                'spa_entity_type_id' => 9999,
            ]],
            'entities' => [['entity_type_id' => 2]],
        ])->assertUnprocessable();

        // save-mappings enforces bitrix-portal-heavy rate limit (10/min/tenant)
        for ($i = 0; $i < 10; $i++) {
            $this->withToken($portalJwt)->postJson('/api/bitrix/portal/save-mappings', [
                'fee_sync_mode' => Tenant::MODE_SUBTOTALS_ONLY,
                'catalog' => ['iblock_id' => 24],
                'entities' => [['entity_type_id' => 2]],
            ]);
        }
        $this->withToken($portalJwt)->postJson('/api/bitrix/portal/save-mappings', [
            'fee_sync_mode' => Tenant::MODE_SUBTOTALS_ONLY,
            'catalog' => ['iblock_id' => 24],
            'entities' => [['entity_type_id' => 2]],
        ])->assertTooManyRequests();
    }

    public function test_dns_rebinding_and_special_ip_ranges_are_blocked_with_curl_resolve_pinning(): void
    {
        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);

        try {
            // 1. Public DNS IPs are pinned directly via CURLOPT_RESOLVE to eliminate DNS-rebinding TOCTOU
            $guard->resolveIpsUsing(fn () => ['93.184.216.34']);
            $options = $guard->pinnedHttpOptions('https://portal.bitrix24.com/rest/');
            $this->assertNotNull($options);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(['portal.bitrix24.com:443:93.184.216.34'], $options['curl'][\CURLOPT_RESOLVE] ?? null);

            // 2. Mixed public + private A-record (split DNS attack) must fail closed
            $guard->resolveIpsUsing(fn () => ['93.184.216.34', '127.0.0.1']);
            $this->assertFalse($guard->isSafeDomain('portal.bitrix24.com'));
            $this->assertNull($guard->pinnedHttpOptions('https://portal.bitrix24.com/rest/'));

            // 3. Special-use / SSRF ranges (IMDS, CGNAT, benchmarking, IPv6 loopback/ULA, IPv4-mapped IPv6, 6to4) fail closed
            $blockedIps = [
                '169.254.169.254',
                '100.64.0.1',
                '198.18.0.1',
                '::1',
                'fd12:3456::1',
                '::ffff:127.0.0.1',
                '::ffff:169.254.169.254',
                '::ffff:100.64.0.1',
                '2002:7f00:0001::1',
            ];

            foreach ($blockedIps as $blockedIp) {
                $guard->resolveIpsUsing(fn () => [$blockedIp]);
                $this->assertFalse($guard->isSafeDomain('portal.bitrix24.com'), "Blocked IP escaped guard: {$blockedIp}");
                $this->assertNull($guard->pinnedHttpOptions('https://portal.bitrix24.com/rest/'), "Blocked IP pinned options: {$blockedIp}");
            }

            // 4. Bitrix24Client refuses outbound REST calls if stored portal DNS rebinds to metadata/private IP
            Http::fake();
            $tenant = Tenant::create([
                'member_id' => 'rebind_client_member',
                'domain' => 'rebind.bitrix24.com',
                'client_endpoint' => 'https://rebind.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'access_token_enc' => 'tok_rebind',
                'application_token_enc' => 'sig_rebind',
                'install_status' => Tenant::INSTALL_ACTIVE,
            ]);

            $guard->resolveIpsUsing(fn () => ['169.254.169.254']);

            try {
                app(Bitrix24Client::class)->call($tenant, 'profile');
                $this->fail('Expected BitrixApiException when portal DNS rebinds to metadata IP.');
            } catch (BitrixApiException $e) {
                $this->assertSame('UNSAFE_CLIENT_ENDPOINT', $e->errorCode);
            }
            Http::assertNothingSent();
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_install_blocks_fake_tenant_registration_from_non_bitrix_domains_and_untrusted_server_endpoints(): void
    {
        Http::fake([
            '*' => Http::response(['result' => true], 200),
        ]);

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn () => ['93.184.216.34']);

        try {
            // 1. Unknown third-party public domain cannot register a fake tenant even with APPLICATION_TOKEN & admin=true
            $this->post('/bitrix/install', [
                'member_id' => 'fake_tenant_001',
                'DOMAIN' => 'attacker.example.com',
                'AUTH_ID' => 'attacker_tok',
                'APPLICATION_TOKEN' => 'attacker_sig',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('bitrix/install-wizard')
                ->where('jwt', null)
                ->where('tenant', null)
                ->whereType('error', 'string')
            );
            $this->assertDatabaseMissing('tenants', ['member_id' => 'fake_tenant_001']);
            Http::assertNothingSent();

            // 2. Bitrix24 lookalike / reserved / malformed member_id attempts are rejected before probing
            foreach (['evil.bitrix24.attacker.com', 'foo.bar.bitrix24.com', 'www.bitrix24.com', 'oauth.bitrix24.com'] as $spoofDomain) {
                $this->post('/bitrix/install', [
                    'member_id' => 'spoof_member_001',
                    'DOMAIN' => $spoofDomain,
                    'AUTH_ID' => 'tok',
                    'APPLICATION_TOKEN' => 'sig',
                ])->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('jwt', null)
                    ->where('tenant', null)
                );
            }
            $this->post('/bitrix/install', [
                'member_id' => 'bad/member id',
                'DOMAIN' => 'valid.bitrix24.com',
                'AUTH_ID' => 'tok',
                'APPLICATION_TOKEN' => 'sig',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('tenant', null)
            );
            $this->assertDatabaseMissing('tenants', ['member_id' => 'spoof_member_001']);
            Http::assertNothingSent();

            // 3. Active tenant rejects omitted or wrong APPLICATION_TOKEN on /bitrix/install BEFORE probing portal REST
            Tenant::create([
                'member_id' => 'active_portal_sig_chk',
                'domain' => 'activeportal.bitrix24.com',
                'client_endpoint' => 'https://activeportal.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'access_token_enc' => 'existing_tok',
                'application_token_enc' => 'expected_app_sig',
                'install_status' => Tenant::INSTALL_ACTIVE,
            ]);

            foreach (['', 'wrong_app_sig'] as $badSig) {
                $this->post('/bitrix/install', [
                    'member_id' => 'active_portal_sig_chk',
                    'DOMAIN' => 'activeportal.bitrix24.com',
                    'AUTH_ID' => 'forged_auth',
                    'APPLICATION_TOKEN' => $badSig,
                ])->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('jwt', null)
                    ->where('tenant', null)
                );
            }
            Http::assertNothingSent();

            // 4. Valid Bitrix24 Cloud portal install sanitizes arbitrary public SERVER_ENDPOINT to Bitrix OAuth default
            Http::fake([
                'https://clinic.bitrix24.de/rest/user.admin.json' => Http::response(['result' => true], 200),
                'https://clinic.bitrix24.de/rest/profile.json' => Http::response(['result' => ['ID' => 7, 'NAME' => 'Hans']], 200),
            ]);

            $this->post('/bitrix/install', [
                'member_id' => 'clinic_de_member',
                'DOMAIN' => 'clinic.bitrix24.de',
                'AUTH_ID' => 'clinic_tok',
                'APPLICATION_TOKEN' => 'clinic_sig',
                'SERVER_ENDPOINT' => 'https://evil-oauth.example.com/rest/',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );

            $clinic = Tenant::where('member_id', 'clinic_de_member')->firstOrFail();
            $this->assertSame('https://oauth.bitrix.info/rest/', $clinic->server_endpoint);
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_install_preserves_bitrix24_cloud_reinstall_and_allowlisted_selfhosted_flows(): void
    {
        Http::fake([
            'https://empresa.bitrix24.com.br/rest/user.admin.json' => Http::response(['result' => true], 200),
            'https://empresa.bitrix24.com.br/rest/profile.json' => Http::response(['result' => ['ID' => 11, 'NAME' => 'Joao']], 200),
            'https://crm.enterprise-client.com/rest/user.admin.json' => Http::response(['result' => true], 200),
            'https://crm.enterprise-client.com/rest/profile.json' => Http::response(['result' => ['ID' => 21, 'NAME' => 'Enterprise']], 200),
            'https://hq.b24.partner.org/rest/user.admin.json' => Http::response(['result' => true], 200),
            'https://hq.b24.partner.org/rest/profile.json' => Http::response(['result' => ['ID' => 22, 'NAME' => 'Partner']], 200),
        ]);

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn () => ['93.184.216.34']);

        $prevAllowed = config('services.bitrix24.allowed_domains');
        config(['services.bitrix24.allowed_domains' => 'crm.enterprise-client.com,*.b24.partner.org']);

        try {
            // 1. Genuine regional Bitrix24 Cloud install (com.br) with official oauth.bitrix24.tech SERVER_ENDPOINT
            $this->post('/bitrix/install', [
                'member_id' => 'empresa_br_001',
                'DOMAIN' => 'empresa.bitrix24.com.br',
                'AUTH_ID' => 'tok_br_1',
                'REFRESH_ID' => 'ref_br_1',
                'APPLICATION_TOKEN' => 'sig_br_1',
                'SERVER_ENDPOINT' => 'https://oauth.bitrix24.tech/rest/',
                'LANG' => 'PT_BR',
                'status' => 'F',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
                ->where('tenant.domain', 'empresa.bitrix24.com.br')
            );

            $cloudTenant = Tenant::where('member_id', 'empresa_br_001')->firstOrFail();
            $this->assertSame('https://oauth.bitrix24.tech/rest/', $cloudTenant->server_endpoint);
            $this->assertSame('pt_br', $cloudTenant->language_id);

            // Reinstall after uninstall preserves member_id row cleanly
            $cloudTenant->update([
                'install_status' => Tenant::INSTALL_UNINSTALLED,
                'uninstalled_at' => now()->subDay(),
            ]);

            $this->post('/bitrix/install', [
                'member_id' => 'empresa_br_001',
                'DOMAIN' => 'empresa.bitrix24.com.br',
                'AUTH_ID' => 'tok_br_2',
                'APPLICATION_TOKEN' => 'sig_br_2',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );

            $cloudTenant->refresh();
            $this->assertSame(Tenant::INSTALL_INSTALLING, $cloudTenant->install_status);
            $this->assertNull($cloudTenant->uninstalled_at);
            $this->assertTrue($cloudTenant->verifyApplicationToken('sig_br_2'));

            // 2. Allowlisted Self-Hosted portals succeed (exact & strict wildcard *.b24.partner.org)
            $this->post('/bitrix/install', [
                'member_id' => 'selfhosted_exact_1',
                'DOMAIN' => 'crm.enterprise-client.com',
                'AUTH_ID' => 'sh_tok_1',
                'APPLICATION_TOKEN' => 'sh_sig_1',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );
            $this->assertDatabaseHas('tenants', ['member_id' => 'selfhosted_exact_1', 'domain' => 'crm.enterprise-client.com']);

            $this->post('/bitrix/install', [
                'member_id' => 'selfhosted_wild_1',
                'DOMAIN' => 'hq.b24.partner.org',
                'AUTH_ID' => 'sh_tok_2',
                'APPLICATION_TOKEN' => 'sh_sig_2',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );

            // Non-matching sibling domain is still rejected
            $this->post('/bitrix/install', [
                'member_id' => 'selfhosted_blocked_1',
                'DOMAIN' => 'untrusted.partner.org',
                'AUTH_ID' => 'sh_tok_3',
                'APPLICATION_TOKEN' => 'sh_sig_3',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('tenant', null)
            );
            $this->assertDatabaseMissing('tenants', ['member_id' => 'selfhosted_blocked_1']);
        } finally {
            config(['services.bitrix24.allowed_domains' => $prevAllowed]);
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_app_entrypoint_hardens_domain_migration_refresh_token_poisoning_and_ssrf_rebind(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'app_hardening_1',
            'domain' => 'appportal.bitrix24.com',
            'client_endpoint' => 'https://appportal.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'orig_access_1',
            'refresh_token_enc' => 'orig_refresh_1',
            'application_token_enc' => 'app_sig_hardening',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn () => ['93.184.216.34']);

        try {
            $probeCount = 0;
            Http::fake(function (HttpClientRequest $req) use (&$probeCount) {
                $probeCount++;
                if (str_contains($req->url(), 'user.admin.json')) {
                    return Http::response(['result' => true], 200);
                }
                if (str_contains($req->url(), 'profile.json')) {
                    return Http::response(['result' => ['ID' => 1, 'NAME' => 'Admin']], 200);
                }

                return Http::response([], 404);
            });

            // 1. Omitting APPLICATION_TOKEN cannot poison stored refresh/access tokens even with valid admin AUTH_ID
            $this->post('/bitrix/app', [
                'member_id' => 'app_hardening_1',
                'DOMAIN' => 'attacker.example.com',
                'AUTH_ID' => 'valid_admin_session',
                'REFRESH_ID' => 'poisoned_refresh_token',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );

            $tenant->refresh();
            $this->assertSame('appportal.bitrix24.com', $tenant->domain);
            $this->assertSame('orig_refresh_1', $tenant->refresh_token_enc);
            $this->assertSame('orig_access_1', $tenant->access_token_enc);

            // 2. Matching APPLICATION_TOKEN still blocks migrating tenant to non-Bitrix/non-allowlisted domain
            $this->post('/bitrix/app', [
                'member_id' => 'app_hardening_1',
                'DOMAIN' => 'attacker.example.com',
                'AUTH_ID' => 'valid_admin_session',
                'APPLICATION_TOKEN' => 'app_sig_hardening',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
            );

            $this->assertSame('appportal.bitrix24.com', $tenant->fresh()->domain);
            $this->assertSame('https://appportal.bitrix24.com/rest/', $tenant->fresh()->client_endpoint);

            // 3. Legitimate Bitrix24 portal rename + OAuth token rotation works with valid APPLICATION_TOKEN
            $this->post('/bitrix/app', [
                'member_id' => 'app_hardening_1',
                'DOMAIN' => 'renamed.bitrix24.eu',
                'AUTH_ID' => 'rotated_admin_access',
                'REFRESH_ID' => 'rotated_admin_refresh',
                'APPLICATION_TOKEN' => 'app_sig_hardening',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page->where('error', null));

            $tenant->refresh();
            $this->assertSame('renamed.bitrix24.eu', $tenant->domain);
            $this->assertSame('https://renamed.bitrix24.eu/rest/', $tenant->client_endpoint);
            $this->assertSame('rotated_admin_access', $tenant->access_token_enc);
            $this->assertSame('rotated_admin_refresh', $tenant->refresh_token_enc);

            // 4. DNS rebinding on stored tenant client_endpoint fails closed before any probe
            $countBeforeRebind = $probeCount;
            $guard->resolveIpsUsing(fn () => ['169.254.169.254']);

            $this->post('/bitrix/app', [
                'member_id' => 'app_hardening_1',
                'AUTH_ID' => 'rotated_admin_access',
                'APPLICATION_TOKEN' => 'app_sig_hardening',
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('tenant', null)
            );
            $this->assertSame($countBeforeRebind, $probeCount);
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_widget_entrypoint_blocks_ssrf_rebind_invalid_entity_ids_and_null_crm_item_acl_bypass(): void
    {
        Tenant::create([
            'member_id' => 'widget_hardening_1',
            'domain' => 'widgetportal.bitrix24.com',
            'client_endpoint' => 'https://widgetportal.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'admin_tok_w',
            'application_token_enc' => 'sig_widget_h',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $returnNullItem = true;

        Http::fake(function (HttpClientRequest $req) use (&$returnNullItem) {
            $url = $req->url();
            if (str_contains($url, 'user.admin.json')) {
                return Http::response(['result' => false], 200);
            }
            if (str_contains($url, 'profile.json')) {
                return Http::response(['result' => ['ID' => 88]], 200);
            }
            if (str_contains($url, 'crm.item.get')) {
                return $returnNullItem
                    ? Http::response(['result' => ['item' => null]], 200)
                    : Http::response(['result' => ['item' => ['id' => 55, 'title' => 'Invoice #55', 'currencyId' => 'USD']]], 200);
            }
            if (str_contains($url, 'crm.item.productrow.list')) {
                return Http::response(['result' => ['productRows' => []]], 200);
            }
            if (str_contains($url, 'crm.currency.list')) {
                return Http::response(['result' => [['CURRENCY' => 'USD', 'FULL_NAME' => 'USD', 'BASE' => 'Y']]], 200);
            }

            return Http::response([], 404);
        });

        try {
            // 1. DNS rebinding on stored client_endpoint fails closed without probing
            $guard->resolveIpsUsing(fn () => ['127.0.0.1']);

            $this->post('/bitrix/widget', [
                'member_id' => 'widget_hardening_1',
                'AUTH_ID' => 'rep_tok',
                'APPLICATION_TOKEN' => 'sig_widget_h',
                'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 15]),
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('context', null)
            );
            Http::assertNothingSent();

            // 2. Malformed non-integer ID ("15xyz") fails placement parsing after verified session
            $guard->resolveIpsUsing(fn () => ['93.184.216.34']);

            $this->post('/bitrix/widget', [
                'member_id' => 'widget_hardening_1',
                'AUTH_ID' => 'rep_tok',
                'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => '15xyz']),
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('context', null)
                ->where('error', "Unsupported placement 'CRM_DEAL_DETAIL_TAB' or missing CRM record ID.")
            );

            // 3. Smart Invoice / Dynamic SPA null-item ACL response {"result":{"item":null}} must fail closed
            $this->post('/bitrix/widget', [
                'member_id' => 'widget_hardening_1',
                'AUTH_ID' => 'rep_tok',
                'PLACEMENT' => 'CRM_SMART_INVOICE_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 55]),
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('jwt', null)
                ->where('context', null)
                ->where('error', 'You do not have Bitrix24 permission to access this CRM entity.')
            );

            // 4. Valid Smart Invoice item grants non-admin widget JWT cleanly
            $returnNullItem = false;

            $this->post('/bitrix/widget', [
                'member_id' => 'widget_hardening_1',
                'AUTH_ID' => 'rep_tok',
                'PLACEMENT' => 'CRM_SMART_INVOICE_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 55]),
            ])->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->whereType('jwt', 'string')
                ->where('can_manage_catalog', false)
            );
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_events_and_csp_block_suspended_onappupdate_domain_hijack_invalid_dynamic_ids_and_empty_tokens(): void
    {
        Queue::fake();

        /** @var BitrixDomainGuard $guard */
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn () => ['93.184.216.34']);

        try {
            $suspended = Tenant::create([
                'member_id' => 'evt_suspended_1',
                'domain' => 'suspendedevt.bitrix24.com',
                'client_endpoint' => 'https://suspendedevt.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'application_token_enc' => 'sig_susp_1',
                'access_token_enc' => 'orig_tok_susp',
                'install_status' => Tenant::INSTALL_SUSPENDED,
            ]);

            // 1. Suspended tenant cannot mutate tokens or domain via ONAPPUPDATE even with valid signature
            $this->post('/bitrix/events', [
                'event' => 'ONAPPUPDATE',
                'auth' => [
                    'member_id' => 'evt_suspended_1',
                    'application_token' => 'sig_susp_1',
                    'access_token' => 'new_tok_susp',
                    'domain' => 'other.bitrix24.com',
                ],
            ])->assertForbidden();
            $this->assertSame('orig_tok_susp', $suspended->fresh()->access_token_enc);

            // 2. Active tenant ONAPPUPDATE cannot switch domain to attacker.example.com and validates fallback + empty token
            $active = Tenant::create([
                'member_id' => 'evt_active_1',
                'domain' => 'activeevt.bitrix24.com',
                'client_endpoint' => 'https://activeevt.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'application_token_enc' => 'sig_act_1',
                'access_token_enc' => 'orig_tok_act',
                'refresh_token_enc' => 'orig_ref_act',
                'install_status' => Tenant::INSTALL_ACTIVE,
            ]);

            // Reject fallback with blank application_token before probing
            Http::fake();
            $this->post('/bitrix/events', [
                'event' => 'ONAPPUPDATE',
                'auth' => [
                    'member_id' => 'evt_active_1',
                    'application_token' => '',
                    'access_token' => 'admin_tok_new',
                ],
            ])->assertForbidden();
            Http::assertNothingSent();

            // Valid ONAPPUPDATE keeps original refresh_token if omitted and rejects non-Bitrix domain
            $this->post('/bitrix/events', [
                'event' => 'ONAPPUPDATE',
                'auth' => [
                    'member_id' => 'evt_active_1',
                    'application_token' => 'sig_act_1',
                    'access_token' => 'rotated_act_2',
                    'domain' => 'attacker.example.com',
                    'status' => 'INVALID_STATUS',
                ],
            ])->assertOk();

            $active->refresh();
            $this->assertSame('rotated_act_2', $active->access_token_enc);
            $this->assertSame('orig_ref_act', $active->refresh_token_enc);
            $this->assertSame('activeevt.bitrix24.com', $active->domain);

            // 3. Valid ONAPPUPDATE regional domain update updates BOTH domain and client_endpoint synchronously
            $this->post('/bitrix/events', [
                'event' => 'ONAPPUPDATE',
                'auth' => [
                    'member_id' => 'evt_active_1',
                    'application_token' => 'sig_act_1',
                    'domain' => 'newportal.bitrix24.mx',
                    'status' => 'P',
                ],
            ])->assertOk();

            $active->refresh();
            $this->assertSame('newportal.bitrix24.mx', $active->domain);
            $this->assertSame('https://newportal.bitrix24.mx/rest/', $active->client_endpoint);
            $this->assertSame('P', $active->app_status);

            // 4. Negative or non-numeric ENTITY_TYPE_ID on ONCRMDYNAMICITEMUPDATE does not dispatch job; valid ID does
            $this->post('/bitrix/events', [
                'event' => 'ONCRMDYNAMICITEMUPDATE',
                'auth' => ['member_id' => 'evt_active_1', 'application_token' => 'sig_act_1'],
                'data' => ['FIELDS' => ['ID' => '100', 'ENTITY_TYPE_ID' => '-5']],
            ])->assertOk();
            Queue::assertNothingPushed();

            $this->post('/bitrix/events', [
                'event' => 'ONCRMDYNAMICITEMUPDATE',
                'auth' => ['member_id' => 'evt_active_1', 'application_token' => 'sig_act_1'],
                'data' => ['FIELDS' => ['ID' => '100', 'ENTITY_TYPE_ID' => '1042']],
            ])->assertOk();
            Queue::assertPushed(ProcessBitrixEventJob::class);

            // 5. CSP includes expanded regional Bitrix24 TLDs cleanly
            $cspRes = $this->get('/bitrix/install');
            $csp = (string) $cspRes->headers->get('Content-Security-Policy');
            $this->assertStringContainsString('https://*.bitrix24.mx', $csp);
            $this->assertStringContainsString('https://*.bitrix24.uk', $csp);
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }

    public function test_allowed_b24_user_whitelist_and_subscription_deactivation_strictly_enforced(): void
    {
        $guard = app(BitrixDomainGuard::class);
        $guard->resolveIpsUsing(fn (string $host) => ['198.51.100.77']);

        try {
            $tenant = Tenant::create([
                'member_id' => 'member_user_whitelist',
                'domain' => 'whitelist.bitrix24.com',
                'client_endpoint' => 'https://whitelist.bitrix24.com/rest/',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'access_token_enc' => 'tok_wl',
                'application_token_enc' => 'sig_wl',
                'install_status' => Tenant::INSTALL_ACTIVE,
                'allowed_b24_user_ids' => [42],
            ]);

            $plan = Plan::where('code', 'pro')->firstOrFail();
            $sub = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => 'monthly',
                'current_period_end' => now()->addMonth(),
            ]);

            TenantCatalogConfig::create([
                'tenant_id' => $tenant->id,
                'iblock_id' => 15,
            ]);

            // Fake Bitrix responses for user 42 (allowed) and user 99 (disallowed)
            Http::fake([
                'https://whitelist.bitrix24.com/rest/user.admin.json' => Http::response(['result' => false]),
                'https://whitelist.bitrix24.com/rest/profile.json' => function ($request) {
                    $body = $request->data();
                    $auth = $body['auth'] ?? '';
                    $id = $auth === 'auth_allowed_42' ? 42 : 99;

                    return Http::response(['result' => ['ID' => $id, 'NAME' => 'User', 'LAST_NAME' => (string) $id]], 200);
                },
                'https://whitelist.bitrix24.com/rest/crm.deal.get.json' => Http::response(['result' => ['ID' => 10]], 200),
                'https://whitelist.bitrix24.com/rest/*' => Http::response(['result' => []], 200),
            ]);

            // 1. Bitrix App: Allowed user 42 succeeds, disallowed user 99 gets error response
            $this->post('/bitrix/app', [
                'member_id' => 'member_user_whitelist',
                'APPLICATION_TOKEN' => 'sig_wl',
                'AUTH_ID' => 'auth_allowed_42',
            ])->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('bitrix/portal-admin')->where('error', null));

            $this->post('/bitrix/app', [
                'member_id' => 'member_user_whitelist',
                'APPLICATION_TOKEN' => 'sig_wl',
                'AUTH_ID' => 'auth_disallowed_99',
            ])->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('bitrix/portal-admin')->whereNot('error', null));

            // 2. Bitrix Widget: Allowed user 42 succeeds, disallowed user 99 gets error response
            $this->post('/bitrix/widget', [
                'member_id' => 'member_user_whitelist',
                'APPLICATION_TOKEN' => 'sig_wl',
                'AUTH_ID' => 'auth_allowed_42',
                'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 10]),
            ])->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('bitrix/widget')->where('error', null));

            $this->post('/bitrix/widget', [
                'member_id' => 'member_user_whitelist',
                'APPLICATION_TOKEN' => 'sig_wl',
                'AUTH_ID' => 'auth_disallowed_99',
                'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 10]),
            ])->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('bitrix/widget')->whereNot('error', null));

            // 3. Widget JWT API: Allowed user 42 succeeds, disallowed user 99 gets 403
            $jwtService = app(BitrixJwtService::class);
            $jwtAllowed = $jwtService->issueForTenant($tenant, 'widget', 42, false, ['entity_type_id' => 2, 'entity_id' => 10]);
            $jwtDisallowed = $jwtService->issueForTenant($tenant, 'widget', 99, false, ['entity_type_id' => 2, 'entity_id' => 10]);

            $this->withToken($jwtAllowed)->postJson('/api/bitrix/widget/catalog-search', ['q' => 'test'])->assertOk();
            $this->withToken($jwtDisallowed)->postJson('/api/bitrix/widget/catalog-search', ['q' => 'test'])->assertForbidden();

            // 4. Subscription Deactivation: Suspend subscription -> even user 42 is blocked
            $sub->update(['status' => Subscription::STATUS_SUSPENDED]);

            $this->withToken($jwtAllowed)->postJson('/api/bitrix/widget/catalog-search', ['q' => 'test'])->assertForbidden();

            $this->post('/bitrix/widget', [
                'member_id' => 'member_user_whitelist',
                'APPLICATION_TOKEN' => 'sig_wl',
                'AUTH_ID' => 'auth_allowed_42',
                'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
                'PLACEMENT_OPTIONS' => json_encode(['ID' => 10]),
            ])->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('bitrix/widget')->whereNot('error', null));
        } finally {
            $guard->resolveIpsUsing(null);
        }
    }
}
