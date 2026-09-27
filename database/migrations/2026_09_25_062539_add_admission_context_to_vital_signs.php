<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 74: Make triage_assessment_id nullable so vital signs can be recorded
 * against an inpatient encounter without a triage assessment, and add
 * admission_id so inpatient vitals can be filtered by admission.
 *
 * Inpatient vitals: triage_assessment_id = NULL, admission_id = set.
 * OPD/triage vitals: triage_assessment_id = set, admission_id = NULL.
 * Both remain valid via the NOT NULL → nullable change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->foreignId('triage_assessment_id')->nullable()->change();

            $table->foreignId('admission_id')
                ->nullable()
                ->after('triage_assessment_id')
                ->constrained('admissions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admission_id');
            $table->foreignId('triage_assessment_id')->nullable(false)->change();
        });
    }
};
