<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Dedicated Fetal Assessment & Monitoring table (Phase 4).
     * Strictly separate from maternal vital signs, supporting repeated measurements,
     * multi-fetal pregnancies (distinguishing each fetus), and historical progress tracking.
     */
    public function up(): void
    {
        Schema::create('fetal_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maternity_profile_id')->constrained('maternity_profiles')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('reception_visit_id')->nullable()->constrained('reception_visits')->nullOnDelete();
            $table->foreignId('clinical_encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();

            $table->dateTime('assessed_at');
            $table->decimal('gestational_age_weeks', 4, 1)->nullable();

            // Multi-fetal tracking
            $table->unsignedTinyInteger('number_of_fetuses')->default(1);
            $table->string('fetus_identifier', 50)->default('Fetus A'); // e.g. Fetus A, Fetus B, Fetus C

            // Fetal Wellbeing & Growth Parameters
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable(); // bpm
            $table->string('fetal_movements', 50)->nullable(); // normal, reduced, absent, increased
            $table->decimal('fundal_height_cm', 4, 1)->nullable(); // cm
            $table->string('presentation', 50)->nullable(); // cephalic, breech, shoulder, face, compound, unstabilised
            $table->string('lie', 50)->nullable(); // longitudinal, transverse, oblique, variable
            $table->unsignedSmallInteger('estimated_fetal_weight_grams')->nullable(); // grams

            // Sonography and Clinical Remarks
            $table->text('ultrasound_findings')->nullable();
            $table->text('clinical_notes')->nullable();

            $table->timestamps();

            $table->index(['maternity_profile_id', 'assessed_at']);
            $table->index(['patient_id', 'assessed_at']);
            $table->index(['maternity_profile_id', 'fetus_identifier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fetal_assessments');
    }
};
