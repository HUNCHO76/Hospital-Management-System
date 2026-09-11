<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology examination (Phase 5, Step 41). Extends the same
 * RadiologyAppointment row (Steps 39-40) rather than a new
 * radiology_examinations table — READY -> IN_PROGRESS -> COMPLETED/ABORTED
 * are further states of the exact same per-order-item appointment already
 * carrying scheduling/reception history, keeping every procedure on a
 * multi-procedure order independently trackable (Part 4) without a second
 * entity to keep in sync with the first. See RadiologyAppointment's own
 * doc-comment for the full reuse-vs-new-table reasoning this step follows.
 *
 * `technical_details` is a single JSON bag rather than one sparse column
 * per modality-specific field (view/projection, body_part, laterality for
 * X-Ray; protocol/contrast for CT; coil for MRI; etc.) — mirrors the
 * `radiology_procedures.body_sites`/`radiology_order_items.body_site_snapshot`
 * precedent of "JSON for catalog-shaped data that varies by row" rather than
 * a wide table with mostly-null columns. The allowed keys per modality are
 * enforced server-side by RadiologyExaminationService, never trusted
 * unchecked from the client.
 *
 * `resource_name` (Step 39) is reused as the examination's actual resource —
 * no second "actual vs scheduled" column — corrected in place at start()
 * only when it genuinely changes, with its own distinct audit event
 * (RADIOLOGY_EXAMINATION_RESOURCE_CHANGED) rather than a silent overwrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_appointments', function (Blueprint $table) {
            $table->foreignId('performed_by')->nullable()->after('ready_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable()->after('performed_by');

            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->restrictOnDelete();

            $table->text('technical_notes')->nullable()->after('completed_by');
            $table->json('technical_details')->nullable()->after('technical_notes');

            $table->timestamp('aborted_at')->nullable()->after('technical_details');
            $table->foreignId('aborted_by')->nullable()->after('aborted_at')->constrained('users')->restrictOnDelete();
            $table->text('abort_reason')->nullable()->after('aborted_by');

            $table->index('started_at');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_appointments', function (Blueprint $table) {
            $table->dropColumn([
                'performed_by', 'started_at',
                'completed_at', 'completed_by',
                'technical_notes', 'technical_details',
                'aborted_at', 'aborted_by', 'abort_reason',
            ]);
        });
    }
};
