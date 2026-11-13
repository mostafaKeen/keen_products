<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('billing_type', 32)->default('fixed')->after('currency');
            $table->unsignedInteger('price_per_user_cents')->nullable()->after('billing_type');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('seats_count')->default(1)->after('plan_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('allowed_b24_user_ids')->nullable()->after('installer_b24_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('allowed_b24_user_ids');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('seats_count');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['billing_type', 'price_per_user_cents']);
        });
    }
};

