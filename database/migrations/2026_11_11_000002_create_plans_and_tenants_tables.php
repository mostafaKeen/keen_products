<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('price_monthly_cents')->default(0);
            $table->unsignedInteger('price_yearly_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('trial_days')->default(14);
            $table->unsignedInteger('max_monthly_syncs')->nullable(); // null = unlimited
            $table->unsignedInteger('max_options')->default(2);
            $table->json('features_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('member_id', 128)->unique();
            $table->string('domain', 255)->index();
            $table->string('portal_name', 255)->nullable();
            $table->string('client_endpoint', 512);
            $table->string('server_endpoint', 512)->default('https://oauth.bitrix.info/rest/');
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('application_token_enc')->nullable();
            $table->string('app_status', 8)->default('F');
            $table->string('install_status', 32)->default('installing')->index(); // installing|active|uninstalled|suspended
            $table->string('license_family', 32)->nullable();
            $table->string('license_type', 64)->nullable();
            $table->string('language_id', 16)->default('en');
            $table->unsignedBigInteger('installer_b24_user_id')->nullable();
            $table->string('installer_name', 255)->nullable();
            $table->string('fee_sync_mode', 32)->default('dedicated_spas'); // dedicated_spas|consolidated_spas|subtotals_only
            $table->string('schema_status', 32)->default('unconfigured'); // unconfigured|provisioned|mapped|error
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamp('last_api_call_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('settings_json')->nullable();
            $table->json('schema_snapshot_json')->nullable();
            $table->timestamp('schema_snapshot_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 32)->default('trialing')->index(); // trialing|active|grace_period|past_due|suspended|expired|canceled
            $table->string('billing_cycle', 16)->default('monthly'); // monthly|yearly|manual
            $table->string('billing_provider', 32)->default('manual'); // manual|stripe|paddle
            $table->string('external_customer_id', 128)->nullable();
            $table->string('external_subscription_id', 128)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('custom_limits_json')->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('upgrade_requested_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('upgrade_requested_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'subscriptions_tenant_status_idx');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 32)->default('paid')->index(); // pending|paid|failed|refunded|void
            $table->string('provider', 32)->default('manual');
            $table->string('reference', 128)->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'paid_at'], 'sub_payments_tenant_paid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
