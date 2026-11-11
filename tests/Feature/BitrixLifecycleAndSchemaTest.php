<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\BitrixJwtService;
use App\Services\Bitrix24\BitrixSchemaProvisioner;
use App\Services\Bitrix24\BitrixSchemaScanner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BitrixLifecycleAndSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'services.bitrix24.client_id' => 'local.test.client',
            'services.bitrix24.client_secret' => 'local.test.secret',
            'services.bitrix24.oauth_endpoint' => 'https://oauth.bitrix.info/oauth/token/',
        ]);
    }

    public function test_refreshes_expired_oauth_tokens_reactively_and_chunks_large_batch_calls(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_oauth_batch',
            'domain' => 'oauth.bitrix24.com',
            'client_endpoint' => 'https://oauth.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'expired_tok',
            'refresh_token_enc' => 'refresh_tok_1',
            'token_expires_at' => now()->addHour(),
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        $callSequence = [];

        Http::fake(function (HttpClientRequest $req) use (&$callSequence) {
            $url = $req->url();
            $callSequence[] = $url;

            if (str_contains($url, 'oauth.bitrix.info')) {
                return Http::response([
                    'access_token' => 'fresh_tok_2',
                    'refresh_token' => 'refresh_tok_2',
                    'expires_in' => 3600,
                ], 200);
            }

            if (str_contains($url, 'app.info') && $req['auth'] === 'expired_tok') {
                return Http::response([
                    'error' => 'expired_token',
                    'error_description' => 'The access token provided has expired.',
                ], 401);
            }

            if (str_contains($url, 'app.info') && $req['auth'] === 'fresh_tok_2') {
                return Http::response([
                    'result' => ['LICENSE' => 'en_ent', 'ID' => 1],
                    'time' => ['operating' => 0.1],
                ], 200);
            }

            if (str_contains($url, 'batch')) {
                $cmds = $req['cmd'] ?? [];
                $resMap = [];
                foreach ($cmds as $k => $_) {
                    $resMap[$k] = ['ok' => $k];
                }

                return Http::response([
                    'result' => [
                        'result' => $resMap,
                        'result_error' => [],
                    ],
                    'time' => ['operating' => 0.2],
                ], 200);
            }

            return Http::response(['result' => []], 200);
        });

        $client = app(Bitrix24Client::class);
        $info = $client->call($tenant, 'app.info');

        $this->assertSame('en_ent', $info['result']['LICENSE']);
        $tenant->refresh();
        $this->assertSame('fresh_tok_2', $tenant->access_token_enc);
        $this->assertSame('refresh_tok_2', $tenant->refresh_token_enc);

        // 75 commands must split into exactly 2 batch HTTP requests (50 + 25)
        $commands = [];
        for ($i = 1; $i <= 75; $i++) {
            $commands["c_{$i}"] = ['method' => 'crm.item.get', 'params' => ['id' => $i]];
        }

        $batchRes = $client->batch($tenant, $commands);
        $batchCalls = collect($callSequence)->filter(fn ($u) => str_contains($u, '/batch'));

        $this->assertCount(75, $batchRes);
        $this->assertCount(2, $batchCalls);
    }

    public function test_marketplace_install_complete_uninstall_reinstall_and_token_rotation_lifecycle(): void
    {
        Http::fake([
            'https://market.bitrix24.com/rest/user.admin.json' => Http::response(['result' => true], 200),
            'https://market.bitrix24.com/rest/profile.json' => Http::response([
                'result' => ['ID' => 7, 'NAME' => 'Jane', 'LAST_NAME' => 'Admin'],
            ], 200),
            'https://market.bitrix24.com/rest/placement.get*' => Http::response(['result' => []], 200),
            'https://market.bitrix24.com/rest/placement.bind*' => Http::response(['result' => true], 200),
            'https://market.bitrix24.com/rest/event.get*' => Http::response(['result' => []], 200),
            'https://market.bitrix24.com/rest/event.bind*' => Http::response(['result' => true], 200),
        ]);

        // 1. Install Wizard entry
        $installRes = $this->post('/bitrix/install', [
            'member_id' => 'b24_member_unique_1',
            'DOMAIN' => 'market.bitrix24.com',
            'AUTH_ID' => 'tok_init_1',
            'REFRESH_ID' => 'ref_init_1',
            'AUTH_EXPIRES' => 3600,
            'APPLICATION_TOKEN' => 'app_sig_123',
            'PROTOCOL' => '1',
            'LANG' => 'en',
            'status' => 'F',
        ]);

        $installRes->assertOk();
        $this->assertStringContainsString('frame-ancestors', (string) $installRes->headers->get('Content-Security-Policy'));
        $this->assertFalse($installRes->headers->has('X-Frame-Options'));

        $tenant = Tenant::where('member_id', 'b24_member_unique_1')->firstOrFail();
        $this->assertSame(Tenant::INSTALL_INSTALLING, $tenant->install_status);
        $this->assertSame('Jane Admin', $tenant->installer_name);

        // 2. Complete Install with issued install JWT
        $installJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'install', 7, true);
        $completeRes = $this->withToken($installJwt)->postJson('/api/bitrix/portal/complete-install');

        $completeRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('install_status', Tenant::INSTALL_ACTIVE);

        $this->assertSame(1, $tenant->fresh()->subscriptions()->count());

        // 3. ONAPPUPDATE rotates tokens when application_token matches
        $this->post('/bitrix/events', [
            'event' => 'ONAPPUPDATE',
            'auth' => [
                'member_id' => 'b24_member_unique_1',
                'application_token' => 'app_sig_123',
                'access_token' => 'tok_rotated_2',
                'refresh_token' => 'ref_rotated_2',
                'expires_in' => 3600,
            ],
        ])->assertOk();

        $this->assertSame('tok_rotated_2', $tenant->fresh()->access_token_enc);

        // 4. ONAPPUNINSTALL with invalid signature is rejected, valid signature marks uninstalled & wipes tokens
        $this->post('/bitrix/events', [
            'event' => 'ONAPPUNINSTALL',
            'auth' => ['member_id' => 'b24_member_unique_1', 'application_token' => 'wrong_token'],
        ])->assertForbidden();

        $this->post('/bitrix/events', [
            'event' => 'ONAPPUNINSTALL',
            'auth' => ['member_id' => 'b24_member_unique_1', 'application_token' => 'app_sig_123'],
        ])->assertOk();

        $tenant->refresh();
        $this->assertSame(Tenant::INSTALL_UNINSTALLED, $tenant->install_status);
        $this->assertNull($tenant->access_token_enc);

        // 5. Reinstall on same member_id reuses the exact same tenant row cleanly
        $this->post('/bitrix/install', [
            'member_id' => 'b24_member_unique_1',
            'DOMAIN' => 'market.bitrix24.com',
            'AUTH_ID' => 'tok_reinstall_3',
            'REFRESH_ID' => 'ref_reinstall_3',
            'APPLICATION_TOKEN' => 'app_sig_999',
        ])->assertOk();

        $this->assertSame(1, Tenant::where('member_id', 'b24_member_unique_1')->count());
        $this->assertSame(Tenant::INSTALL_INSTALLING, $tenant->fresh()->install_status);
    }

    public function test_restricts_non_admin_users_cleanly_in_bitrix_app_entrypoint_and_bearer_admin_apis(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_nonadmin',
            'domain' => 'staff.bitrix24.com',
            'client_endpoint' => 'https://staff.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'application_token_enc' => 'app_sig_staff',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        Http::fake([
            'https://staff.bitrix24.com/rest/user.admin.json' => Http::response(['result' => false], 200),
            'https://staff.bitrix24.com/rest/profile.json' => Http::response([
                'result' => ['ID' => 42, 'NAME' => 'Regular', 'LAST_NAME' => 'Rep'],
            ], 200),
        ]);

        $res = $this->post('/bitrix/app', [
            'member_id' => 'member_nonadmin',
            'APPLICATION_TOKEN' => 'app_sig_staff',
            'AUTH_ID' => 'rep_tok',
        ]);

        $res->assertOk();

        $nonAdminJwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'app', 42, false);
        $this->withToken($nonAdminJwt)
            ->postJson('/api/bitrix/portal/scan-schema')
            ->assertForbidden();
    }

    public function test_enforces_list_enum_guardrail_and_falls_back_gracefully_on_spa_tariff_restriction(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_prov_test',
            'domain' => 'prov.bitrix24.com',
            'client_endpoint' => 'https://prov.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_prov',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        $addedPropEnums = [];
        $nextPropId = 100;
        $nextEnumId = 500;
        $nextUfId = 1;

        Http::fake(function (HttpClientRequest $req) use (&$addedPropEnums, &$nextPropId, &$nextEnumId, &$nextUfId) {
            $url = $req->url();

            if (str_contains($url, 'catalog.catalog.list')) {
                return Http::response(['result' => ['catalogs' => [['id' => 24, 'iblockId' => 24, 'name' => 'CRM']]]]);
            }
            if (str_contains($url, 'catalog.productProperty.list')) {
                return Http::response(['result' => ['productProperties' => []]]);
            }
            if (str_contains($url, 'catalog.productProperty.add')) {
                $id = ++$nextPropId;

                return Http::response(['result' => ['productProperty' => [
                    'id' => $id,
                    'code' => $req['fields']['code'],
                    'xmlId' => $req['fields']['xmlId'],
                    'name' => $req['fields']['name'],
                ]]]);
            }
            if (str_contains($url, 'catalog.productPropertyEnum.list')) {
                return Http::response(['result' => ['productPropertyEnums' => []]]);
            }
            if (str_contains($url, 'catalog.productPropertyEnum.add')) {
                $eid = ++$nextEnumId;
                $pid = (int) $req['fields']['propertyId'];
                $addedPropEnums[$pid][] = $req['fields']['xmlId'];

                return Http::response(['result' => ['productPropertyEnum' => [
                    'id' => $eid,
                    'value' => $req['fields']['value'],
                    'xmlId' => $req['fields']['xmlId'],
                ]]]);
            }
            if (str_contains($url, 'crm.type.list')) {
                return Http::response(['result' => ['types' => []]]);
            }
            if (str_contains($url, 'crm.type.add')) {
                return Http::response([
                    'error' => 'CREATE_DYNAMIC_TYPE_RESTRICTED',
                    'error_description' => 'Smart Process creation is restricted on Free tariff.',
                ], 400);
            }
            if (str_contains($url, 'crm.deal.userfield.list') || str_contains($url, 'crm.deal.fields')) {
                return Http::response(['result' => []]);
            }
            if (str_contains($url, 'crm.deal.userfield.add')) {
                $n = ++$nextUfId;

                return Http::response(['result' => $n]);
            }
            if (str_contains($url, 'placement.get') || str_contains($url, 'event.get')) {
                return Http::response(['result' => []]);
            }
            if (str_contains($url, 'placement.bind') || str_contains($url, 'event.bind')) {
                return Http::response(['result' => true]);
            }

            return Http::response(['result' => []]);
        });

        /** @var BitrixSchemaProvisioner $provisioner */
        $provisioner = app(BitrixSchemaProvisioner::class);
        $res = $provisioner->provisionAuto($tenant, [
            'fee_sync_mode' => Tenant::MODE_DEDICATED_SPAS,
            'entity_type_ids' => [2],
            // Intentionally pass ONLY 1 option to verify Bitrix < 2 list option BoolEnum guardrail
            'options_config' => [
                ['key' => 'option_1', 'label' => 'Single Option', 'sort' => 100, 'is_enabled' => true],
            ],
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame(Tenant::MODE_SUBTOTALS_ONLY, $res['fee_sync_mode']);
        $this->assertStringContainsString('Subtotals-Only', collect($res['warnings'])->implode(' '));

        // Option property (prop 102) must have >= 2 enums so Bitrix does not turn it into BoolEnum
        $this->assertCount(2, $addedPropEnums[102]);
        $this->assertStringContainsString('_OTHER', $addedPropEnums[102][1]);
    }

    public function test_scans_portal_schema_and_generates_field_suggestions_across_differing_portal_codes(): void
    {
        $tenant = Tenant::create([
            'member_id' => 'member_scan',
            'domain' => 'scan.bitrix24.com',
            'client_endpoint' => 'https://scan.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_scan',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        Http::fake([
            'https://scan.bitrix24.com/rest/app.info*' => Http::response(['result' => ['LICENSE' => 'en_pro']]),
            'https://scan.bitrix24.com/rest/crm.currency.list*' => Http::response(['result' => [['CURRENCY' => 'AED', 'FULL_NAME' => 'Dirham', 'BASE' => 'Y']]]),
            'https://scan.bitrix24.com/rest/catalog.catalog.list*' => Http::response(['result' => ['catalogs' => [['iblockId' => 55, 'name' => 'Custom Catalog']]]]),
            'https://scan.bitrix24.com/rest/catalog.productProperty.list*' => Http::response(['result' => ['productProperties' => [
                ['id' => 301, 'name' => 'Type of Cost', 'code' => 'TYPE_OF_COST', 'xmlId' => 'TYPE_OF_COST', 'propertyType' => 'L'],
                ['id' => 302, 'name' => 'Option', 'code' => 'OPTION', 'xmlId' => 'OPTION', 'propertyType' => 'L'],
            ]]]),
            'https://scan.bitrix24.com/rest/catalog.productPropertyEnum.list*' => Http::response(['result' => ['productPropertyEnums' => [
                ['id' => '9001', 'value' => 'Professional', 'xmlId' => 'PROF', 'sort' => 100],
                ['id' => '9002', 'value' => 'Government', 'xmlId' => 'GOV', 'sort' => 200],
            ]]]),
            'https://scan.bitrix24.com/rest/crm.type.list*' => Http::response(['result' => ['types' => [
                ['id' => 9, 'entityTypeId' => 1090, 'title' => 'Professional Fees - Option 1'],
            ]]]),
            'https://scan.bitrix24.com/rest/crm.item.fields*' => Http::response(['result' => ['fields' => []]]),
            'https://scan.bitrix24.com/rest/userfieldconfig.list*' => Http::response(['result' => ['fields' => []]]),
            'https://scan.bitrix24.com/rest/crm.lead.fields*' => Http::response(['result' => []]),
            'https://scan.bitrix24.com/rest/crm.deal.fields*' => Http::response(['result' => [
                'UF_CRM_999111' => ['type' => 'money', 'formLabel' => 'sub_total_p_option1'],
                'UF_CRM_999222' => ['type' => 'string', 'formLabel' => 'utm_check'],
            ]]),
        ]);

        $snapshot = app(BitrixSchemaScanner::class)->scanPortal($tenant, [2]);

        $this->assertSame(55, $snapshot['catalog']['iblock_id']);
        $this->assertSame('property301', $snapshot['suggestions']['catalog_properties']['cost_type_prop_code']);
        $this->assertSame('property302', $snapshot['suggestions']['catalog_properties']['option_prop_code']);
        $this->assertSame(1090, $snapshot['suggestions']['spa_buckets']['prof_opt1']);
        $this->assertSame('UF_CRM_999111', $snapshot['suggestions']['entities']['2']['subtotal']['prof_opt1']);
        $this->assertSame('UF_CRM_999222', $snapshot['suggestions']['entities']['2']['sync_status']);
    }

    public function test_merges_configured_allowed_frame_ancestors_and_renders_home_with_plans(): void
    {
        config(['services.bitrix24.allowed_frame_ancestors' => 'https://crm.acme.test, https://b24.internal:8443']);

        $res = $this->get('/bitrix/install');
        $res->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://crm.acme.test', $csp);
        $this->assertStringContainsString('https://b24.internal:8443', $csp);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('welcome')->has('plans')->has('app_urls'));

        $this->get('/features')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('landing/features')->has('app_urls'));

        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('landing/pricing')->has('plans'));
    }
}
