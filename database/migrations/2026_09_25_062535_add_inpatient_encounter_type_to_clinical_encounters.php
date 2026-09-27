<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 74: Add 'inpatient' to the allowed encounter_type values and add
 * admission_id FK so an inpatient encounter links back to its admission.
 * reception_visit_id and appointment_id remain nullable (inpatient encounters
 * have neither).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->foreignId('admission_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('admissions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admission_id');
        });
    }
};
