<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription verification (Phase 6, Step 47) — a genuinely pharmacy-owned
 * entity, one row per PrescriptionOrder, never retrofitted onto that order's
 * own row. This mirrors the explicit boundary PrescriptionOrder/
 * PrescriptionOrderItem's own docblocks already declared before this step
 * existed ("Step 47's verification status belongs to a future pharmacy-owned
 * entity, never retrofitted here") and the same precedent
 * LaboratoryProcessing/LaboratoryResult established for Laboratory's own
 * processing status versus LaboratoryOrder.
 *
 * One row per order (not per item, unlike LaboratoryResult): the
 * verification UI mockup (spec §22) reviews every medication on the
 * prescription together and renders a single Verify/Return decision for the
 * whole slip, matching how a pharmacist actually reviews a written
 * prescription.
 *
 * No persisted UNDER_REVIEW status: mirrors RadiologyReport's own explicit
 * precedent (Phase 5, Step 43) — "conflicting concurrent verification is
 * prevented by row locking plus the status check inside verify()/
 * returnForCorrection() itself, not by an intermediate persisted state".
 * `recordReviewStarted()` (PrescriptionVerificationService) only ever emits
 * an audit event, never a status transition, mirroring
 * RadiologyReportService::recordReviewStarted() exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('pending_verification');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_verifications');
    }
};
