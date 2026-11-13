<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('synced_spa_links', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('external_id');
            $table->index(['tenant_id', 'external_id'], 'synced_spa_links_ext_idx');
        });
    }

    public function down(): void
    {
        Schema::table('synced_spa_links', function (Blueprint $table) {
            $table->dropIndex('synced_spa_links_ext_idx');
            $table->dropColumn('payload_hash');
        });
    }
};
