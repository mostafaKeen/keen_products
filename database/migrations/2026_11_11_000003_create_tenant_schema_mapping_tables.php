<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_catalog_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('iblock_id')->nullable();
            $table->string('catalog_name', 255)->nullable();
            $table->string('cost_type_prop_code', 64)->nullable();
            $table->unsignedBigInteger('cost_type_prop_id')->nullable();
            $table->string('option_prop_code', 64)->nullable();
            $table->unsignedBigInteger('option_prop_id')->nullable();
            $table->string('payments_prop_code', 64)->nullable();
            $table->unsignedBigInteger('payments_prop_id')->nullable();
            $table->json('extra_attributes_json')->nullable();
            $table->json('options_config_json')->nullable();
            $table->json('cost_types_config_json')->nullable();
            $table->json('option_meta_schema_json')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_spa_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('bucket_key', 64); // prof_opt1, gov_opt1, consolidated_prof, consolidated_all, etc.
            $table->string('title', 255);
            $table->string('cost_type_key', 64)->nullable();
            $table->string('option_key', 64)->nullable();
            $table->unsignedBigInteger('spa_id')->nullable(); // userfieldconfig id (CRM_{spa_id})
            $table->unsignedBigInteger('spa_entity_type_id')->nullable(); // crm.item entityTypeId (>= 128 or >= 1030)
            $table->boolean('is_active')->default(true);
            $table->json('field_map_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'bucket_key'], 'tenant_spa_buckets_unique');
        });

        Schema::create('tenant_entity_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('logical_key', 64); // lead, deal, quote, spa_1086
            $table->string('title', 255);
            $table->unsignedBigInteger('entity_type_id');
            $table->string('owner_type_code', 16); // L, D, Q, T43e
            $table->string('placement_code', 128);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->timestamp('last_bound_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type_id'], 'tenant_entity_configs_unique');
        });

        Schema::create('tenant_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('entity_config_id')->constrained('tenant_entity_configs')->cascadeOnDelete();
            $table->string('role', 32); // subtotal, option_total, spa_link, option_meta, sync_status
            $table->string('role_qualifier', 128); // prof_opt1, option_1, option_1.business_activity, default
            $table->string('b24_field_code', 128);
            $table->string('b24_field_type', 64)->nullable();
            $table->json('b24_settings_json')->nullable();
            $table->timestamps();

            $table->unique(['entity_config_id', 'role', 'role_qualifier'], 'tenant_field_mappings_unique');
        });

        Schema::create('tenant_enum_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('mapping_group', 32); // cost_type, option_group, payment_schedule, custom_attr
            $table->string('attr_key', 64); // cost_type, option_group, payment_schedule, visa_type, etc.
            $table->string('logical_key', 64);
            $table->string('logical_label', 255);
            $table->string('catalog_prop_code', 64)->nullable();
            $table->string('catalog_enum_id', 64)->nullable();
            $table->string('catalog_enum_xml_id', 128)->nullable();
            $table->unsignedBigInteger('spa_entity_type_id')->default(0); // 0 = shared catalog meaning; >0 = SPA enum target
            $table->string('spa_field_code', 128)->nullable();
            $table->string('spa_enum_id', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'mapping_group', 'catalog_enum_id'], 'tenant_enum_cat_idx');
            $table->index(['tenant_id', 'spa_entity_type_id', 'spa_field_code'], 'tenant_enum_spa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_enum_mappings');
        Schema::dropIfExists('tenant_field_mappings');
        Schema::dropIfExists('tenant_entity_configs');
        Schema::dropIfExists('tenant_spa_buckets');
        Schema::dropIfExists('tenant_catalog_configs');
    }
};
