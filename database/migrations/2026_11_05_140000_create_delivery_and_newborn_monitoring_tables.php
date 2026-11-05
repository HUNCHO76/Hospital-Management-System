<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhances delivery records and establishes independent newborn records and growth monitoring (Phases 7 & 8):
     * - Make admission_id nullable on delivery_records (supporting outpatient deliveries).
     * - Add reception_visit_id, birth_order, and baby_name to delivery_records.
     * - Add mother_patient_id to patients table.
     * - Create newborn_growth_records table for serial growth and developmental monitoring.
     */
    public function up(): void
    {
        // 1. Enhance delivery_records
        Schema::table('delivery_records', function (Blueprint $table) {
            $table->unsignedBigInteger('admission_id')->nullable()->change();

            if (! Schema::hasColumn('delivery_records', 'reception_visit_id')) {
                $table->foreignId('reception_visit_id')
                    ->nullable()
                    ->after('clinical_encounter_id')
                    ->constrained('reception_visits')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('delivery_records', 'birth_order')) {
                $table->unsignedTinyInteger('birth_order')->default(1)->after('baby_outcome');
            }

            if (! Schema::hasColumn('delivery_records', 'baby_name')) {
                $table->string('baby_name', 150)->nullable()->after('birth_order');
            }
        });

        // 2. Enhance patients table with maternal linkage
        Schema::table('patients', function (Blueprint $table) {
            if (! Schema::hasColumn('patients', 'mother_patient_id')) {
                $table->foreignId('mother_patient_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('patients')
                    ->nullOnDelete();
            }
        });

        // 3. Create newborn_growth_records table
        if (! Schema::hasTable('newborn_growth_records')) {
            Schema::create('newborn_growth_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
                $table->foreignId('mother_patient_id')->nullable()->constrained('patients')->nullOnDelete();
                $table->foreignId('maternity_profile_id')->nullable()->constrained('maternity_profiles')->nullOnDelete();
                $table->foreignId('delivery_record_id')->nullable()->constrained('delivery_records')->nullOnDelete();
                $table->foreignId('reception_visit_id')->nullable()->constrained('reception_visits')->nullOnDelete();
                $table->foreignId('clinical_encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
                $table->dateTime('recorded_at');
                $table->unsignedInteger('age_days')->default(0);

                // Measurements
                $table->decimal('weight_kg', 5, 2);
                $table->decimal('length_height_cm', 5, 1);
                $table->decimal('head_circumference_cm', 4, 1);
                $table->decimal('temperature_c', 4, 1)->nullable();

                // Nutrition & Development
                $table->string('feeding_method', 50)->default('exclusive_breastfeeding');
                $table->json('immunizations_given')->nullable();
                $table->text('developmental_milestones')->nullable();
                $table->string('growth_reference_standard', 100)->default('WHO Child Growth Standards 2006');
                $table->text('clinical_notes')->nullable();

                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['patient_id', 'recorded_at']);
                $table->index('mother_patient_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newborn_growth_records');

        Schema::table('patients', function (Blueprint $table) {
            if (Schema::hasColumn('patients', 'mother_patient_id')) {
                $table->dropConstrainedForeignId('mother_patient_id');
            }
        });

        Schema::table('delivery_records', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_records', 'reception_visit_id')) {
                $table->dropConstrainedForeignId('reception_visit_id');
            }
            if (Schema::hasColumn('delivery_records', 'baby_name')) {
                $table->dropColumn('baby_name');
            }
            if (Schema::hasColumn('delivery_records', 'birth_order')) {
                $table->dropColumn('birth_order');
            }
        });
    }
};
