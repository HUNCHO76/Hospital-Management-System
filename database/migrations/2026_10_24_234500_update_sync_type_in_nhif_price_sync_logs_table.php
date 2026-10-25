<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nhif_price_sync_logs')) {
            Schema::table('nhif_price_sync_logs', function (Blueprint $table) {
                $table->string('sync_type', 50)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nhif_price_sync_logs')) {
            Schema::table('nhif_price_sync_logs', function (Blueprint $table) {
                $table->enum('sync_type', ['price_list', 'excluded_services', 'package_with_exclusions', 'items', 'packages', 'scheme_services', 'diseases', 'package_prices', 'item_types'])->change();
            });
        }
    }
};
