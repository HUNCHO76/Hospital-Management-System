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
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            // unique(): one consultation per encounter, mirroring
            // triage_assessments — a re-consultation workflow would model
            // additional rounds as its own concept rather than a second row
            // here.
            $table->foreignId('clinical_encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('practitioner_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();

            $table->text('chief_complaint')->nullable();
            $table->text('history_presenting_illness')->nullable();
            $table->text('relevant_medical_history')->nullable();
            $table->text('relevant_surgical_history')->nullable();
            $table->text('relevant_social_history')->nullable();
            $table->text('review_of_systems')->nullable();
            $table->text('physical_examination')->nullable();
            $table->text('assessment_summary')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('plan_summary')->nullable();

            $table->timestamps();

            // No separate index('clinical_encounter_id') — unique() above
            // already creates one.
            $table->index('practitioner_id');
            $table->index('status');
            $table->index('started_at');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
