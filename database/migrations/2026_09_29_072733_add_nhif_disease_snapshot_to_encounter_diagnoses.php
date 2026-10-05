<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounter_diagnoses', function (Blueprint $table) {
            $table->string('nhif_disease_code')->nullable()->after('diagnosis_code_id')->index();
            $table->string('nhif_disease_name')->nullable()->after('nhif_disease_code');
            $table->string('nhif_icd_version_code')->nullable()->after('nhif_disease_name');
        });
    }

    public function down(): void
    {
        Schema::table('encounter_diagnoses', function (Blueprint $table) {
            $table->dropColumn(['nhif_disease_code', 'nhif_disease_name', 'nhif_icd_version_code']);
        });
    }
};
