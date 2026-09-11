<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology findings & results (Phase 5, Step 42) — one report per completed
 * examination (`radiology_appointments`, the same row Steps 39-41 already
 * carry scheduling/reception/examination on). Deliberately keyed to
 * `radiology_appointment_id` alone, not a duplicated `radiology_order_id`/
 * `radiology_order_item_id`/`patient_id`/`encounter_id`/`facility_id` —
 * every one of those is reached transitively through the appointment's own
 * chain (appointment -> item -> order -> encounter -> patient/department),
 * exactly the "no duplicated identity columns" convention every other
 * Radiology table in this app already follows (see the Step 39 migration's
 * own doc-comment). `clinical_indication` is deliberately NOT a column here
 * either — the report must always read the order's own live
 * `clinical_indication`, never an editable copy that could drift out of
 * sync with (or silently override) the reason the exam was actually
 * ordered.
 *
 * A unique index on `radiology_appointment_id` enforces "one report per
 * examination" at the database level, not just in application code — the
 * same backstop pattern the Step 39 `active_slot_key` unique index uses for
 * double-booking.
 *
 * `findings`/`impression`/`technique`/`recommendations` are plain text, not
 * rich/HTML content (Part 22's own "plain text is preferable unless rich
 * formatting is genuinely required" — nothing here requires it) — this
 * sidesteps the sanitization/stored-XSS surface entirely rather than
 * building and then having to defend a rich-text editor.
 *
 * No `radiology_reports`-specific version/history table was created:
 * Step 42's own scope stops at DRAFT -> SUBMITTED (no
 * verification/amendment exists yet to make historical snapshots
 * meaningful), and the existing `AuditLogService` already answers "who
 * changed this report, when, and what" for every create/update/submit
 * call — mirroring how LaboratoryResult's own draft-editing phase (Steps
 * 33-34) relies on the audit log alone, with a dedicated version/snapshot
 * table (`LaboratoryReport`) only introduced once RELEASE existed (Step
 * 35). A similar dedicated version table can be added the same way once a
 * later step actually needs to freeze released report snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radiology_appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reporting_practitioner_id')->constrained('practitioners')->restrictOnDelete();
            $table->string('report_number')->unique();
            $table->string('status')->default('draft');

            $table->text('technique')->nullable();
            $table->text('findings')->nullable();
            $table->text('impression')->nullable();
            $table->text('recommendations')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_reports');
    }
};
