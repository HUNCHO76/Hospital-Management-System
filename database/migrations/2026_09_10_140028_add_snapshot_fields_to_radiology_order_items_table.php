<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 37 added `modality`/`body_sites` to the radiology procedure catalog
 * after these order items were first built (Step 36), so neither was ever
 * captured at order time. Step 38, Part 5 explicitly calls for
 * `modality_snapshot`/`body_site_snapshot` alongside the existing
 * `procedure_code`/`procedure_name` snapshots, for the same historical-
 * integrity reason: a later catalog edit must never change what an
 * existing order is displayed as having actually ordered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_order_items', function (Blueprint $table) {
            $table->string('modality_snapshot')->nullable()->after('procedure_name');
            $table->json('body_site_snapshot')->nullable()->after('modality_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_order_items', function (Blueprint $table) {
            $table->dropColumn(['modality_snapshot', 'body_site_snapshot']);
        });
    }
};
