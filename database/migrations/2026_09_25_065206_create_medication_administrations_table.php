<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medication Administration Record (MAR) — the execution layer that sits
 * AFTER pharmacy dispensing. Stock is already deducted at dispensing time
 * (Phase 6, Step 48, StockService::allocateFefo()). This table records the
 * clinical moment when nursing staff actually administered the medication at
 * the bedside.
 *
 * Columns:
 * - admission_id / clinical_encounter_id: the inpatient context
 * - prescription_order_id / prescription_order_item_id: what was ordered
 * - dispensing_item_id: which dispensed lot was used (nullable — a PRN or
 *   stat administration may precede a dispensing record in some workflows)
 * - status: administered | held | refused | missed
 * - scheduled_at: when the dose was due (derived from frequency, stored for
 *   the worklist view; nullable for PRN/stat)
 * - administered_at: when the administration actually happened
 * - dose / dose_unit: actual dose given (may differ from prescribed if the
 *   clinician explicitly documents an intentional variance)
 * - route: actual route used
 * - is_prn: true when the administration was for an as-needed dose
 * - administered_by: FK to users
 * - reason: free-text reason (required for held/refused/missed)
 * - notes: optional clinical notes
 * - corrects_id: self-referential FK — when an administration record is
 *   amended, the correction row points back to the row it supersedes.
 *   Mirrors ClinicalNote's own amendment-chain pattern exactly.
 * - correction_reason: required when corrects_id is set
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_administrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('prescription_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('prescription_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensing_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('administered_at')->useCurrent();

            $table->string('dose');
            $table->string('dose_unit');
            $table->string('route');
            $table->boolean('is_prn')->default(false);

            $table->foreignId('administered_by')->constrained('users')->restrictOnDelete();

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            // Amendment chain — mirrors ClinicalNote::$corrects_id pattern
            $table->foreignId('corrects_id')->nullable()->references('id')->on('medication_administrations')->nullOnDelete();
            $table->text('correction_reason')->nullable();

            $table->timestamps();

            // Worklist queries: all active administrations for an admission
            $table->index(['admission_id', 'status'], 'mar_admission_status_idx');
            // Per-item history queries
            $table->index(['prescription_order_item_id', 'status'], 'mar_item_status_idx');
            // Chronological timeline
            $table->index('administered_at', 'mar_administered_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
    }
};
