<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_forbids_non_super_admin_users_from_accessing_admin_routes(): void
    {
        $regular = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($regular)->get('/dashboard')->assertOk();
        $this->actingAs($regular)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($regular)->get('/admin/tenants')->assertForbidden();
        $this->actingAs($regular)->get('/admin/plans')->assertForbidden();
        $this->actingAs($regular)->get('/admin/subscriptions')->assertForbidden();
        $this->actingAs($regular)->get('/admin/system-health')->assertForbidden();
    }

    public function test_allows_super_admin_to_inspect_portals_probe_suspend_and_manage_plans_and_payments(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $tenant = Tenant::create([
            'member_id' => 'member_admin_suite',
            'domain' => 'client.bitrix24.com',
            'client_endpoint' => 'https://client.bitrix24.com/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
            'access_token_enc' => 'tok_admin_suite',
            'install_status' => Tenant::INSTALL_ACTIVE,
        ]);

        $starterPlan = Plan::where('code', 'starter')->firstOrFail();

        $sub = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $starterPlan->id,
            'status' => Subscription::STATUS_TRIALING,
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->addDays(10),
            'upgrade_requested_plan_id' => Plan::where('code', 'pro')->firstOrFail()->id,
            'upgrade_requested_at' => now(),
        ]);

        // 1. Dashboard & index views render 200
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin)->get('/admin/tenants')->assertOk();
        $this->actingAs($admin)->get("/admin/tenants/{$tenant->id}")->assertOk();
        $this->actingAs($admin)->get('/admin/plans')->assertOk();
        $this->actingAs($admin)->get('/admin/subscriptions')->assertOk();
        $this->actingAs($admin)->get('/admin/system-health')->assertOk();

        // 2. Super admin probes Bitrix24 portal app.info
        Http::fake([
            'https://client.bitrix24.com/rest/app.info*' => Http::response([
                'result' => ['LICENSE' => 'en_ent'],
            ]),
        ]);

        $this->actingAs($admin)
            ->post("/admin/tenants/{$tenant->id}/probe")
            ->assertRedirect();

        $this->assertSame('en_ent', $tenant->fresh()->license_type);

        // 3. Suspend & reactivate tenant
        $this->actingAs($admin)
            ->patch("/admin/tenants/{$tenant->id}/status", ['install_status' => Tenant::INSTALL_SUSPENDED])
            ->assertRedirect();

        $this->assertSame(Tenant::INSTALL_SUSPENDED, $tenant->fresh()->install_status);

        $this->actingAs($admin)
            ->patch("/admin/tenants/{$tenant->id}/status", ['install_status' => Tenant::INSTALL_ACTIVE])
            ->assertRedirect();

        $this->assertSame(Tenant::INSTALL_ACTIVE, $tenant->fresh()->install_status);

        // 4. Create new Subscription Plan
        $this->actingAs($admin)->post('/admin/plans', [
            'code' => 'agency_plus',
            'name' => 'Agency Plus',
            'description' => 'Multi-portal reseller plan',
            'price_monthly_cents' => 29900,
            'price_yearly_cents' => 299000,
            'currency' => 'usd',
            'trial_days' => 14,
            'max_monthly_syncs' => 25000,
            'max_options' => 8,
            'sort_order' => 45,
            'is_active' => true,
            'is_public' => true,
            'features_json' => [
                'allow_auto_background_sync' => true,
                'allow_bidirectional_spa_sync' => true,
                'allow_custom_spas' => true,
                'allow_custom_product_attributes' => true,
                'allow_backfill' => true,
            ],
        ])->assertRedirect();

        $agencyPlan = Plan::where('code', 'agency_plus')->firstOrFail();
        $this->assertSame('USD', $agencyPlan->currency);

        // 5. Record Payment -> activates subscription, updates period end, and clears upgrade request
        $this->actingAs($admin)->post("/admin/tenants/{$tenant->id}/payments", [
            'plan_id' => $agencyPlan->id,
            'amount_cents' => 299000,
            'currency' => 'USD',
            'billing_cycle' => 'yearly',
            'provider' => 'wire',
            'invoice_number' => 'INV-2026-001',
            'reference' => 'WIRE-9988',
        ])->assertRedirect();

        $sub->refresh();
        $this->assertSame($agencyPlan->id, $sub->plan_id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame('yearly', $sub->billing_cycle);
        $this->assertNull($sub->upgrade_requested_plan_id);
        $this->assertTrue(SubscriptionPayment::where('invoice_number', 'INV-2026-001')->exists());

        // 6. Override subscription custom quota limits
        $this->actingAs($admin)->put("/admin/tenants/{$tenant->id}/subscription", [
            'plan_id' => $agencyPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'yearly',
            'max_monthly_syncs_override' => 99999,
            'max_options_override' => 10,
            'admin_notes' => 'VIP override',
        ])->assertRedirect();

        $sub->refresh();
        $this->assertSame(99999, $sub->effectiveMaxMonthlySyncs());
        $this->assertSame(10, $sub->effectiveMaxOptions());
        $this->assertGreaterThanOrEqual(4, AdminAuditLog::where('tenant_id', $tenant->id)->count());
    }
}
