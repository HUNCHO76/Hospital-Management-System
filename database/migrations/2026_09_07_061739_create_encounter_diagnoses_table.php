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
        Schema::create('encounter_diagnoses', function (Blueprint $table) {
            $table->id();
            // Not unique(): unlike triage_assessments/consultations, multiple
            // diagnoses (primary + secondary) are expected per encounter.
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            // Nullable + auto-set by the service, never user-supplied — the
            // consultation this diagnosis was documented under.
            $table->foreignId('consultation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('diagnosis_code_id')->constrained()->restrictOnDelete();
            $table->string('diagnosis_type');
            $table->string('status')->default('provisional');
            $table->text('clinical_note')->nullable();
            $table->foreignId('diagnosed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('diagnosed_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestamps();

            $table->index('clinical_encounter_id');
            $table->index('diagnosis_code_id');
            $table->index('diagnosis_type');
            $table->index('status');
            $table->index('diagnosed_by');
            $table->index('diagnosed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encounter_diagnoses');
    }
};
