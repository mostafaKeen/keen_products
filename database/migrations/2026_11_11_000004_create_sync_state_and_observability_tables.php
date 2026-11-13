<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_entity_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_type_id');
            $table->unsignedBigInteger('entity_id');
            $table->string('last_hash', 64);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type_id', 'entity_id'], 'synced_entity_states_unique');
        });

        Schema::create('synced_spa_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_entity_type_id');
            $table->unsignedBigInteger('parent_entity_id');
            $table->unsignedBigInteger('product_row_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('bucket_key', 64);
            $table->unsignedBigInteger('spa_entity_type_id');
            $table->unsignedBigInteger('spa_item_id');
            $table->string('external_id', 128);
            $table->timestamps();

            $table->unique(['tenant_id', 'spa_entity_type_id', 'spa_item_id'], 'synced_spa_links_item_unique');
            $table->index(['tenant_id', 'parent_entity_type_id', 'parent_entity_id'], 'synced_spa_links_parent_idx');
        });

        Schema::create('tenant_usage_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->unsignedInteger('sync_count')->default(0);
            $table->unsignedInteger('api_calls_count')->default(0);
            $table->unsignedInteger('webhook_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year_month'], 'tenant_usage_monthly_unique');
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_type_id')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('trigger_source', 32); // widget|webhook|backfill|spa_reverse|lifecycle
            $table->string('status', 32)->index(); // success|skipped|failed|blocked
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('api_calls_count')->default(0);
            $table->unsignedInteger('actions_count')->default(0);
            $table->json('actions_json')->nullable();
            $table->json('errors_json')->nullable();
            $table->double('operating_max')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'sync_logs_tenant_created_idx');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('action', 64);
            $table->json('details_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'admin_audit_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('tenant_usage_monthly');
        Schema::dropIfExists('synced_spa_links');
        Schema::dropIfExists('synced_entity_states');
    }
};
