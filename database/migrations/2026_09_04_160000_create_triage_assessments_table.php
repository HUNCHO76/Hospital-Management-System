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
        Schema::create('triage_assessments', function (Blueprint $table) {
            $table->id();
            // unique(): one primary triage assessment per encounter — a
            // deliberate scope decision (spec §17), not a technical
            // limitation. A future reassessment workflow would model it as
            // its own concept rather than a second row here.
            $table->foreignId('clinical_encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assessment_time')->nullable();
            $table->string('triage_category')->nullable();
            $table->string('chief_complaint')->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            // No separate index('clinical_encounter_id') — unique() above
            // already creates one.
            $table->index('assessed_by');
            $table->index('status');
            $table->index('assessment_time');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('triage_assessments');
    }
};
