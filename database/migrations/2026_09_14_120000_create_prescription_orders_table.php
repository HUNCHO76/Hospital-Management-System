<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prescription order — a clinician's medication decision placed directly
 * against a ClinicalEncounter (Phase 6, Step 46). Prescription is its own
 * sibling branch off the encounter, alongside Care Plan/Laboratory/Radiology/
 * Diagnosis/Clinical Notes — never nested under a CarePlanItem, mirroring
 * radiology_orders/laboratory_orders exactly. The pre-existing `prescriptions`
 * table (Phase 3, Steps 24-25, nested 1:1 on a medication-type CarePlanItem)
 * is left untouched for the care plan's own simple medication notes — see
 * docs/pharmacy.md for the full reasoning behind keeping both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            $table->string('prescription_number')->unique();
            $table->string('status')->default('active');
            $table->text('clinical_notes')->nullable();
            $table->foreignId('prescriber_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('prescribed_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('clinical_encounter_id');
            $table->index('status');
            $table->index('prescribed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_orders');
    }
};
