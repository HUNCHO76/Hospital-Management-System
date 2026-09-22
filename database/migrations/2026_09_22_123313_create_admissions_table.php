<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HMS hospital admissions (Phase 8, Step 70).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->string('admission_number', 40)->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_insurance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admission_type')->nullable();
            $table->string('ward_type')->nullable();
            $table->string('room_type')->nullable();
            $table->text('diagnosis_at_admission')->nullable();
            $table->foreignId('practitioner_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('charges_per_day', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30);
            $table->timestamp('admitted_at');
            $table->timestamp('discharged_at')->nullable();
            $table->string('discharge_type')->nullable();
            $table->text('discharge_reason')->nullable();
            $table->foreignId('admitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index('admitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
