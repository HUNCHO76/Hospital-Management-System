<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF's own RequestServices API requires an `authorizationNo` identifying
 * the patient's current visit/attendance — this app has no automatic way to
 * acquire one yet (that requires a separate NHIF point-of-care registration
 * call not built in this step), so it is captured from the requesting user
 * at preauthorization creation time instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_preauthorizations', function (Blueprint $table) {
            $table->string('authorization_no')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('nhif_preauthorizations', function (Blueprint $table) {
            $table->dropColumn('authorization_no');
        });
    }
};
