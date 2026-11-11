<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEnumMapping;
use App\Models\TenantFieldMapping;
use App\Services\Bitrix24\BitrixFieldAdapter;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BitrixFieldAdapterAndSubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_and_formats_bitrix24_money_values_consistently(): void
    {
        $adapter = app(BitrixFieldAdapter::class);

        $this->assertSame('1250.50|AED', $adapter->formatMoney(1250.5, 'aed'));
        $this->assertSame('99.00|USD', $adapter->formatMoney(99, ''));
        $this->assertSame(['amount' => 2500.75, 'currency' => 'EUR'], $adapter->parseMoney('2500.75|EUR'));
        $this->assertSame(['amount' => 400.0, 'currency' => null], $adapter->parseMoney(400));
        $this->assertSame(['amount' => 0.0, 'currency' => null], $adapter->parseMoney('invalid'));
    }

    public function test_reads_uf_crm_and_uf_crm_casing_interchangeably_and_normalizes_bindings(): void
    {
        $adapter = app(BitrixFieldAdapter::class);

        $record = [
            'ufCrm29_1784036987' => '3500|USD',
            'UF_CRM_CUSTOM_TAG' => 'VIP',
        ];

        $this->assertSame('3500|USD', $adapter->readFieldValue($record, 'UF_CRM_29_1784036987'));
        $this->assertSame('VIP', $adapter->readFieldValue($record, 'ufCrmCustomTag'));
        $this->assertSame('ufCrm29_1784036987', $adapter->toCamelCaseUf('UF_CRM_29_1784036987'));
        $this->assertSame('ufCrmCustomTag', $adapter->toCamelCaseUf('UF_CRM_CUSTOM_TAG'));

        $singleMapping = new TenantFieldMapping([
            'b24_settings_json' => ['DYNAMIC_1058' => 'Y'],
        ]);
        $multiMapping = new TenantFieldMapping([
            'b24_settings_json' => ['DYNAMIC_1058' => 'Y', 'DEAL' => 'Y'],
        ]);

        $this->assertSame([12, 15], $adapter->formatCrmBindingValue([12, 15, 12], $singleMapping));
        $this->assertSame(['T422_12'], $adapter->formatCrmBindingValue([12], $multiMapping));
        $this->assertSame([12, 18], $adapter->normalizeLinkedIds(['T422_12', 18, 'D_18']));
    }

    public function test_resolves_catalog_enum_keys_and_maps_to_spa_enum_ids_per_tenant(): void
    {
        $adapter = app(BitrixFieldAdapter::class);

        $tenant = Tenant::create([
            'member_id' => 'member_enum_test',
            'domain' => 'enum.bitrix24.com',
            'client_endpoint' => 'https://enum.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
        ]);

        TenantEnumMapping::create([
            'tenant_id' => $tenant->id,
            'mapping_group' => 'cost_type',
            'attr_key' => 'cost_type',
            'logical_key' => 'professional',
            'logical_label' => 'Professional',
            'catalog_prop_code' => 'property77',
            'catalog_enum_id' => '401',
            'spa_entity_type_id' => 0,
        ]);

        TenantEnumMapping::create([
            'tenant_id' => $tenant->id,
            'mapping_group' => 'cost_type',
            'attr_key' => 'cost_type',
            'logical_key' => 'professional',
            'logical_label' => 'Professional',
            'catalog_enum_id' => '401',
            'spa_entity_type_id' => 1044,
            'spa_field_code' => 'ufCrm7_COST',
            'spa_enum_id' => '8801',
        ]);

        $this->assertSame('professional', $adapter->resolveLogicalKeyFromCatalogEnum($tenant, 'cost_type', 'cost_type', '401'));
        $this->assertSame('401', $adapter->resolveCatalogEnumIdFromLogicalKey($tenant, 'cost_type', 'cost_type', 'professional'));
        $this->assertSame('8801', $adapter->mapCatalogEnumToSpaEnum($tenant, 1044, 'ufCrm7_COST', '401'));
    }

    public function test_manages_trial_to_grace_to_expired_transitions_and_monthly_sync_quota_gating(): void
    {
        $gate = app(SubscriptionGate::class);

        $plan = Plan::create([
            'code' => 'trial',
            'name' => 'Free Trial',
            'price_monthly_cents' => 0,
            'price_yearly_cents' => 0,
            'currency' => 'USD',
            'trial_days' => 14,
            'max_monthly_syncs' => 5,
            'max_options' => 2,
            'features_json' => ['allow_auto_background_sync' => true],
        ]);

        $tenant = Tenant::create([
            'member_id' => 'member_sub_gate',
            'domain' => 'gate.bitrix24.com',
            'client_endpoint' => 'https://gate.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        // 1. Fresh trial active
        $eval = $gate->evaluate($tenant);
        $this->assertTrue($eval['allowed']);
        $this->assertSame(Subscription::STATUS_TRIALING, $eval['status']);
        $this->assertSame(5, $eval['remaining_syncs']);

        // 2. Trial expired within grace window (3 days past trial_ends_at < 7d grace period)
        $sub = $eval['subscription'];
        $sub->update(['trial_ends_at' => now()->subDays(3)]);
        $evalGrace = $gate->evaluate($tenant->fresh());
        $this->assertTrue($evalGrace['allowed']);
        $this->assertSame(Subscription::STATUS_GRACE_PERIOD, $evalGrace['status']);
        $this->assertNotNull($evalGrace['banner']);

        // 3. Grace period elapsed -> expired & blocked
        $sub->refresh()->update(['grace_ends_at' => now()->subHour()]);
        $evalExpired = $gate->evaluate($tenant->fresh());
        $this->assertFalse($evalExpired['allowed']);
        $this->assertSame(Subscription::STATUS_EXPIRED, $evalExpired['status']);

        // 4. Active subscription blocked when monthly sync quota reached
        $sub->refresh()->update([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_end' => now()->addMonth(),
            'grace_ends_at' => null,
        ]);
        $gate->incrementUsage($tenant, 5, 10, 0);

        $evalQuota = $gate->evaluate($tenant->fresh(), requireSyncQuota: true);
        $this->assertFalse($evalQuota['allowed']);
        $this->assertStringContainsString('Monthly sync quota reached', (string) $evalQuota['reason']);

        // View-only evaluation remains allowed even at quota cap
        $evalReadOnly = $gate->evaluate($tenant->fresh(), requireSyncQuota: false);
        $this->assertTrue($evalReadOnly['allowed']);

        // 5. Suspended portal blocked
        $tenant->update(['install_status' => Tenant::INSTALL_SUSPENDED]);
        $evalSuspended = $gate->evaluate($tenant->fresh(), requireSyncQuota: false);
        $this->assertFalse($evalSuspended['allowed']);
    }
}
