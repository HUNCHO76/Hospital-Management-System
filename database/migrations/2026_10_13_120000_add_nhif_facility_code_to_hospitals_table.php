<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->string('nhif_facility_code', 50)->nullable()->after('code');
        });

        // Seed default NHIF facility code for existing main hospital if configured
        $defaultNhifFacilityCode = config('nhif.client_id', '01141') ?: '01141';

        DB::table('hospitals')
            ->where('code', 'MAIN')
            ->whereNull('nhif_facility_code')
            ->update(['nhif_facility_code' => $defaultNhifFacilityCode]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropColumn('nhif_facility_code');
        });
    }
};
