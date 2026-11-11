<?php

namespace Tests\Feature;

use App\Jobs\ProcessBitrixEventJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SyncedEntityState;
use App\Models\SyncedSpaLink;
use App\Models\Tenant;
use App\Models\TenantCatalogConfig;
use App\Models\TenantEntityConfig;
use App\Models\TenantEnumMapping;
use App\Models\TenantFieldMapping;
use App\Models\TenantSpaBucket;
use App\Services\Bitrix24\BitrixJwtService;
use App\Services\Subscriptions\SubscriptionGate;
use App\Services\Sync\BidirectionalSpaSyncService;
use App\Services\Sync\TenantSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncEngineAndWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function createConfiguredTenant(string $memberId, string $domain, array $customCodes = []): Tenant
    {
        $tenant = Tenant::create([
            'member_id' => $memberId,
            'domain' => $domain,
            'client_endpoint' => "https://{$domain}/rest/",
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_'.$memberId,
            'install_status' => Tenant::INSTALL_ACTIVE,
            'fee_sync_mode' => Tenant::MODE_DEDICATED_SPAS,
            'schema_status' => 'provisioned',
        ]);

        TenantCatalogConfig::create([
            'tenant_id' => $tenant->id,
            'iblock_id' => $customCodes['iblock_id'] ?? 24,
            'cost_type_prop_code' => $customCodes['cost_prop'] ?? 'property70',
            'option_prop_code' => $customCodes['opt_prop'] ?? 'property71',
            'payments_prop_code' => $customCodes['pay_prop'] ?? 'property72',
            'options_config_json' => TenantCatalogConfig::defaultOptionsConfig(),
            'cost_types_config_json' => TenantCatalogConfig::defaultCostTypesConfig(),
            'option_meta_schema_json' => TenantCatalogConfig::defaultOptionMetaSchema(),
        ]);

        TenantSpaBucket::create([
            'tenant_id' => $tenant->id,
            'bucket_key' => 'prof_opt1',
            'title' => 'Professional Option 1',
            'cost_type_key' => 'professional',
            'option_key' => 'option_1',
            'spa_id' => 10,
            'spa_entity_type_id' => $customCodes['spa_type_id'] ?? 1058,
            'is_active' => true,
            'field_map_json' => [
                'title' => 'title',
                'xml_id' => 'xmlId',
                'price' => 'opportunity',
                'quantity' => $customCodes['spa_qty_field'] ?? 'ufCrm10_QTY',
                'cost_type' => $customCodes['spa_cost_field'] ?? 'ufCrm10_COST',
                'option_group' => $customCodes['spa_opt_field'] ?? 'ufCrm10_OPT',
            ],
        ]);

        $ec = TenantEntityConfig::create([
            'tenant_id' => $tenant->id,
            'logical_key' => 'deal',
            'title' => 'Deals',
            'entity_type_id' => 2,
            'owner_type_code' => 'D',
            'placement_code' => 'CRM_DEAL_DETAIL_TAB',
            'is_enabled' => true,
            'auto_sync_enabled' => true,
        ]);

        TenantFieldMapping::create([
            'tenant_id' => $tenant->id,
            'entity_config_id' => $ec->id,
            'role' => TenantFieldMapping::ROLE_SUBTOTAL,
            'role_qualifier' => 'prof_opt1',
            'b24_field_code' => $customCodes['sub_field'] ?? 'UF_CRM_PROF1',
            'b24_field_type' => 'money',
        ]);
        TenantFieldMapping::create([
            'tenant_id' => $tenant->id,
            'entity_config_id' => $ec->id,
            'role' => TenantFieldMapping::ROLE_OPTION_TOTAL,
            'role_qualifier' => 'option_1',
            'b24_field_code' => $customCodes['tot_field'] ?? 'UF_CRM_OPT1_TOTAL',
            'b24_field_type' => 'money',
        ]);
        TenantFieldMapping::create([
            'tenant_id' => $tenant->id,
            'entity_config_id' => $ec->id,
            'role' => TenantFieldMapping::ROLE_SPA_LINK,
            'role_qualifier' => 'prof_opt1',
            'b24_field_code' => $customCodes['lnk_field'] ?? 'UF_CRM_LNK1',
            'b24_field_type' => 'crm',
        ]);
        TenantFieldMapping::create([
            'tenant_id' => $tenant->id,
            'entity_config_id' => $ec->id,
            'role' => TenantFieldMapping::ROLE_SYNC_STATUS,
            'role_qualifier' => 'default',
            'b24_field_code' => $customCodes['status_field'] ?? 'UF_CRM_SYNC_OK',
            'b24_field_type' => 'string',
        ]);

        TenantEnumMapping::create([
            'tenant_id' => $tenant->id,
            'mapping_group' => 'cost_type',
            'attr_key' => 'cost_type',
            'logical_key' => 'professional',
            'logical_label' => 'Professional',
            'catalog_prop_code' => $customCodes['cost_prop'] ?? 'property70',
            'catalog_enum_id' => '501',
            'spa_entity_type_id' => 0,
        ]);
        TenantEnumMapping::create([
            'tenant_id' => $tenant->id,
            'mapping_group' => 'option_group',
            'attr_key' => 'option_group',
            'logical_key' => 'option_1',
            'logical_label' => 'Option 1',
            'catalog_prop_code' => $customCodes['opt_prop'] ?? 'property71',
            'catalog_enum_id' => '601',
            'spa_entity_type_id' => 0,
        ]);

        return $tenant;
    }

    public function test_executes_atomic_widget_sync_catalog_value_id_preservation_spa_batch_add_and_clear_all(): void
    {
        $tenant = $this->createConfiguredTenant('member_widget_sync', 'sync.bitrix24.com');
        $recorded = [];
        $storedProductRows = [];

        Http::fake(function (HttpClientRequest $req) use (&$recorded, &$storedProductRows) {
            $url = $req->url();
            $recorded[] = ['url' => $url, 'payload' => $req->data()];

            if (str_contains($url, 'crm.deal.get')) {
                return Http::response(['result' => ['ID' => 77, 'TITLE' => 'Deal #77', 'CURRENCY_ID' => 'AED']]);
            }
            if (str_contains($url, 'crm.currency.list')) {
                return Http::response(['result' => [['CURRENCY' => 'AED', 'FULL_NAME' => 'AED', 'BASE' => 'Y']]]);
            }
            if (str_contains($url, 'catalog.product.list')) {
                return Http::response(['result' => ['products' => [
                    ['id' => 11, 'iblockId' => 24, 'name' => 'Advisory Fee', 'property70' => ['value' => '999', 'valueId' => 881]],
                ]]]);
            }
            if (str_contains($url, 'batch')) {
                $cmds = $req['cmd'] ?? [];
                $res = [];
                foreach ($cmds as $k => $cmdStr) {
                    if (str_starts_with($k, 'add_')) {
                        $res[$k] = ['item' => ['id' => 910]];
                    } else {
                        $res[$k] = true;
                    }
                }

                return Http::response(['result' => ['result' => $res, 'result_error' => []]]);
            }
            if (str_contains($url, 'crm.item.productrow.set')) {
                $incoming = (array) ($req['productRows'] ?? []);
                $storedProductRows = [];
                foreach ($incoming as $idx => $r) {
                    $storedProductRows[] = array_merge(['id' => 4001 + $idx], $r);
                }

                return Http::response(['result' => ['productRows' => $storedProductRows]]);
            }
            if (str_contains($url, 'crm.item.productrow.list')) {
                return Http::response(['result' => ['productRows' => $storedProductRows]]);
            }

            return Http::response(['result' => true]);
        });

        $jwt = app(BitrixJwtService::class)->issueForTenant($tenant, 'widget', 5, false, [
            'entity_type_id' => 2,
            'entity_id' => 77,
        ]);

        // 1. Widget Save & Sync
        $syncRes = $this->withToken($jwt)->postJson('/api/bitrix/widget/sync', [
            'currencyId' => 'AED',
            'rows' => [[
                'productId' => 11,
                'productName' => 'Advisory Fee',
                'price' => 1500,
                'quantity' => 2,
                'taxRate' => 5,
                'taxIncluded' => false,
                'costTypeKey' => 'professional',
                'optionKey' => 'option_1',
            ]],
            'optionMeta' => [],
        ]);

        $syncRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.summary.subtotals.prof_opt1', 3000)
            ->assertJsonPath('result.summary.option_totals.option_1', 3000)
            ->assertJsonPath('result.summary.total_with_tax', 3150);

        // Verify catalog update preserved valueId=881
        $catBatch = collect($recorded)->first(fn ($c) => str_contains($c['url'], '/batch') && isset($c['payload']['cmd']['cat_11']));
        $this->assertStringContainsString('valueId', (string) $catBatch['payload']['cmd']['cat_11']);

        // Verify entity money fields & SPA link written with custom portal field names
        $dealUpdate = collect($recorded)->first(fn ($c) => str_contains($c['url'], 'crm.deal.update'));
        $this->assertSame('3000.00|AED', $dealUpdate['payload']['fields']['UF_CRM_PROF1']);
        $this->assertSame([910], $dealUpdate['payload']['fields']['UF_CRM_LNK1']);
        $this->assertSame('Done', $dealUpdate['payload']['fields']['UF_CRM_SYNC_OK']);

        $this->assertTrue(SyncedSpaLink::where('tenant_id', $tenant->id)->where('spa_item_id', 910)->exists());

        // 2. Clear All resets subtotals and removes SPA links
        $clearRes = $this->withToken($jwt)->postJson('/api/bitrix/widget/clear', [
            'currencyId' => 'AED',
            'optionMeta' => [],
        ]);

        $clearRes->assertOk()->assertJsonPath('success', true);
        $this->assertSame(0, SyncedSpaLink::where('tenant_id', $tenant->id)->count());
    }

    public function test_isolates_custom_field_schemas_across_two_tenants(): void
    {
        $tenantA = $this->createConfiguredTenant('member_A', 'porta.bitrix24.com', ['sub_field' => 'UF_CRM_ALPHA']);
        $tenantB = $this->createConfiguredTenant('member_B', 'portb.bitrix24.com', ['sub_field' => 'UF_CRM_BETA']);

        $writtenB = [];
        Http::fake(function (HttpClientRequest $req) use (&$writtenB) {
            $url = $req->url();
            if (str_contains($url, 'crm.deal.get')) {
                return Http::response(['result' => ['ID' => 55, 'TITLE' => 'Deal B', 'CURRENCY_ID' => 'USD']]);
            }
            if (str_contains($url, 'crm.currency.list')) {
                return Http::response(['result' => [['CURRENCY' => 'USD', 'BASE' => 'Y']]]);
            }
            if (str_contains($url, 'crm.item.productrow.set')) {
                return Http::response(['result' => ['productRows' => []]]);
            }
            if (str_contains($url, 'crm.item.productrow.list')) {
                return Http::response(['result' => ['productRows' => []]]);
            }
            if (str_contains($url, 'crm.deal.update')) {
                $writtenB[] = $req['fields'];

                return Http::response(['result' => true]);
            }

            return Http::response(['result' => ['result' => [], 'result_error' => []]]);
        });

        $jwtB = app(BitrixJwtService::class)->issueForTenant($tenantB, 'widget', 1, false, [
            'entity_type_id' => 2,
            'entity_id' => 55,
        ]);

        $this->withToken($jwtB)->postJson('/api/bitrix/widget/sync', [
            'currencyId' => 'USD',
            'rows' => [[
                'productName' => 'Standalone Service',
                'price' => 200,
                'quantity' => 1,
                'costTypeKey' => 'professional',
                'optionKey' => 'option_1',
            ]],
        ])->assertOk();

        $this->assertArrayHasKey('UF_CRM_BETA', $writtenB[0]);
        $this->assertArrayNotHasKey('UF_CRM_ALPHA', $writtenB[0]);

        $this->assertSame(0, SyncedEntityState::where('tenant_id', $tenantA->id)->count());
        $this->assertSame(1, SyncedEntityState::where('tenant_id', $tenantB->id)->count());
    }

    public function test_suppresses_self_write_echoes_and_skips_unchanged_payload_hashes_in_job(): void
    {
        $tenant = $this->createConfiguredTenant('member_echo', 'echo.bitrix24.com');

        Http::fake([
            'https://echo.bitrix24.com/rest/*' => Http::response(['result' => ['ID' => 88, 'CURRENCY_ID' => 'USD']]),
        ]);

        // 1. Self-write lock suppresses job before calling portal
        Cache::put(TenantSyncService::selfWriteCacheKey($tenant->id, 2, 88), true, 15);
        $job = new ProcessBitrixEventJob($tenant->id, 'entity_update', 2, 88);
        app()->call([$job, 'handle']);
        Http::assertNothingSent();

        // 2. Hash match on second webhook avoids writes & records skipped status cleanly
        Cache::forget(TenantSyncService::selfWriteCacheKey($tenant->id, 2, 88));
        Http::fake([
            'https://echo.bitrix24.com/rest/crm.deal.get*' => Http::response(['result' => ['ID' => 88, 'CURRENCY_ID' => 'USD']]),
            'https://echo.bitrix24.com/rest/crm.item.productrow.list*' => Http::response(['result' => ['productRows' => []]]),
            'https://echo.bitrix24.com/rest/crm.deal.update*' => Http::response(['result' => true]),
        ]);

        /** @var TenantSyncService $syncService */
        $syncService = app(TenantSyncService::class);
        $first = $syncService->syncEntity($tenant, 2, 88, 'webhook', null, false);
        $second = $syncService->syncEntity($tenant, 2, 88, 'webhook', null, false);

        $this->assertTrue($first['success']);
        $this->assertTrue((bool) ($second['skipped'] ?? false));
    }

    public function test_performs_o1_bidirectional_spa_to_entity_price_sync_when_enabled(): void
    {
        $tenant = $this->createConfiguredTenant('member_bidir', 'bidir.bitrix24.com');

        $proPlan = Plan::create([
            'code' => 'pro_test',
            'name' => 'Pro',
            'price_monthly_cents' => 14900,
            'price_yearly_cents' => 149000,
            'currency' => 'USD',
            'trial_days' => 14,
            'max_monthly_syncs' => 10000,
            'max_options' => 5,
            'features_json' => [
                'allow_auto_background_sync' => true,
                'allow_bidirectional_spa_sync' => true,
                'allow_custom_spas' => true,
                'allow_custom_product_attributes' => true,
                'allow_backfill' => true,
            ],
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $proPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'billing_provider' => 'manual',
            'current_period_end' => now()->addMonth(),
        ]);

        SyncedSpaLink::create([
            'tenant_id' => $tenant->id,
            'parent_entity_type_id' => 2,
            'parent_entity_id' => 50,
            'spa_entity_type_id' => 1058,
            'spa_item_id' => 700,
            'bucket_key' => 'prof_opt1',
            'external_id' => '2_50_r333',
            'product_row_id' => 333,
        ]);

        $rowUpdatedPayload = null;
        Http::fake(function (HttpClientRequest $req) use (&$rowUpdatedPayload) {
            $url = $req->url();
            if (str_contains($url, 'crm.item.get')) {
                return Http::response(['result' => ['item' => [
                    'id' => 700,
                    'title' => 'Filing Fee',
                    'opportunity' => 1000,
                    'ufCrm10_QTY' => 2,
                ]]]);
            }
            if (str_contains($url, 'crm.item.productrow.list')) {
                return Http::response(['result' => ['productRows' => [
                    ['id' => 333, 'productName' => 'Filing Fee', 'price' => 300, 'quantity' => 2],
                ]]]);
            }
            if (str_contains($url, 'crm.item.productrow.update')) {
                $rowUpdatedPayload = $req->data();

                return Http::response(['result' => true]);
            }
            if (str_contains($url, 'crm.deal.get')) {
                return Http::response(['result' => ['ID' => 50, 'CURRENCY_ID' => 'USD']]);
            }
            if (str_contains($url, 'crm.deal.update')) {
                return Http::response(['result' => true]);
            }

            return Http::response(['result' => true]);
        });

        app(SubscriptionGate::class)->evaluate($tenant);
        $res = app(BidirectionalSpaSyncService::class)->handleSpaItemUpdated($tenant, 1058, 700);

        $this->assertTrue($res['success']);
        $this->assertSame(333, (int) $rowUpdatedPayload['id']);
        $this->assertSame(500.0, (float) $rowUpdatedPayload['fields']['price']);
    }
}
