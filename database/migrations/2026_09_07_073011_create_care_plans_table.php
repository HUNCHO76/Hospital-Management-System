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
        Schema::create('care_plans', function (Blueprint $table) {
            $table->id();
            // unique(): one care plan per encounter, mirroring consultations
            // — a future revised-plan workflow would model additional
            // rounds as its own concept rather than a second row here.
            $table->foreignId('clinical_encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');

            $table->text('goals')->nullable();
            $table->text('clinical_instructions')->nullable();
            $table->text('monitoring_instructions')->nullable();
            $table->text('follow_up_instructions')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
