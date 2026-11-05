<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhances maternity profiles and vital signs for Dedicated Mother Assessment:
     * - Links maternity profile directly to outpatient reception visits
     * - Records pregnancy risk classification, obstetric history, medical history, allergies, current medications
     * - Adds maternity-specific observation fields to vital signs (urine protein/glucose/acetone, blood glucose)
     */
    public function up(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('maternity_profiles', 'reception_visit_id')) {
                $table->foreignId('reception_visit_id')
                    ->nullable()
                    ->after('clinical_encounter_id')
                    ->constrained('reception_visits')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('maternity_profiles', 'previous_pregnancy_history')) {
                $table->text('previous_pregnancy_history')->nullable()->after('para');
            }

            if (! Schema::hasColumn('maternity_profiles', 'medical_history')) {
                $table->text('medical_history')->nullable()->after('previous_pregnancy_history');
            }

            if (! Schema::hasColumn('maternity_profiles', 'obstetric_history')) {
                $table->text('obstetric_history')->nullable()->after('medical_history');
            }

            if (! Schema::hasColumn('maternity_profiles', 'risk_classification')) {
                $table->string('risk_classification', 30)->default('low_risk')->after('risk_factors');
            }

            if (! Schema::hasColumn('maternity_profiles', 'allergies')) {
                $table->text('allergies')->nullable()->after('risk_classification');
            }

            if (! Schema::hasColumn('maternity_profiles', 'current_medications')) {
                $table->text('current_medications')->nullable()->after('allergies');
            }
        });

        Schema::table('vital_signs', function (Blueprint $table) {
            if (! Schema::hasColumn('vital_signs', 'maternity_profile_id')) {
                $table->foreignId('maternity_profile_id')
                    ->nullable()
                    ->after('admission_id')
                    ->constrained('maternity_profiles')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('vital_signs', 'reception_visit_id')) {
                $table->foreignId('reception_visit_id')
                    ->nullable()
                    ->after('maternity_profile_id')
                    ->constrained('reception_visits')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('vital_signs', 'urine_protein')) {
                $table->string('urine_protein', 20)->nullable()->after('bmi');
            }

            if (! Schema::hasColumn('vital_signs', 'urine_glucose')) {
                $table->string('urine_glucose', 20)->nullable()->after('urine_protein');
            }

            if (! Schema::hasColumn('vital_signs', 'urine_acetone')) {
                $table->string('urine_acetone', 20)->nullable()->after('urine_glucose');
            }

            if (! Schema::hasColumn('vital_signs', 'blood_glucose_mmol')) {
                $table->decimal('blood_glucose_mmol', 4, 1)->nullable()->after('urine_acetone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            if (Schema::hasColumn('vital_signs', 'maternity_profile_id')) {
                $table->dropConstrainedForeignId('maternity_profile_id');
            }
            if (Schema::hasColumn('vital_signs', 'reception_visit_id')) {
                $table->dropConstrainedForeignId('reception_visit_id');
            }
            $columnsToDrop = [];
            foreach (['urine_protein', 'urine_glucose', 'urine_acetone', 'blood_glucose_mmol'] as $col) {
                if (Schema::hasColumn('vital_signs', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });

        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('maternity_profiles', 'reception_visit_id')) {
                $table->dropConstrainedForeignId('reception_visit_id');
            }
            $columnsToDrop = [];
            foreach ([
                'previous_pregnancy_history',
                'medical_history',
                'obstetric_history',
                'risk_classification',
                'allergies',
                'current_medications',
            ] as $col) {
                if (Schema::hasColumn('maternity_profiles', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

