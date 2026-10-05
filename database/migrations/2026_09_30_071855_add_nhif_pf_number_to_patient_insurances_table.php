<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->string('nhif_pf_number', 50)->nullable()->after('nhif_facility_code');
        });
    }

    public function down(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->dropColumn('nhif_pf_number');
        });
    }
};
