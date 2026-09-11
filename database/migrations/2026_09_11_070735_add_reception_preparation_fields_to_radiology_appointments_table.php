<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology reception & preparation (Phase 5, Step 40). Extends the same
 * RadiologyAppointment row (Step 39) rather than a new entity — arrival,
 * verification, preparation, screening, consent, and readiness are all
 * further states/attributes of the one appointment already anchored to a
 * single RadiologyOrderItem, which is exactly the "prefer readiness at
 * order-item level" the spec asks for (Part 25): each procedure's own
 * appointment carries its own arrival/prep/ready progress independently.
 *
 * Preparation *instructions* are deliberately NOT duplicated here — they
 * stay read live from RadiologyProcedure.preparation_instructions (Part 8).
 * Only the workflow's own timestamps/outcomes are recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_appointments', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('scheduled_end');
            $table->foreignId('arrived_by')->nullable()->after('arrived_at')->constrained('users')->restrictOnDelete();

            $table->timestamp('verified_at')->nullable()->after('arrived_by');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->restrictOnDelete();

            $table->timestamp('instructions_given_at')->nullable()->after('verified_by');
            $table->foreignId('instructions_given_by')->nullable()->after('instructions_given_at')->constrained('users')->restrictOnDelete();

            $table->timestamp('preparation_completed_at')->nullable()->after('instructions_given_by');
            $table->foreignId('preparation_completed_by')->nullable()->after('preparation_completed_at')->constrained('users')->restrictOnDelete();
            $table->text('preparation_notes')->nullable()->after('preparation_completed_by');

            $table->timestamp('preparation_waived_at')->nullable()->after('preparation_notes');
            $table->foreignId('preparation_waived_by')->nullable()->after('preparation_waived_at')->constrained('users')->restrictOnDelete();
            $table->text('preparation_waived_reason')->nullable()->after('preparation_waived_by');

            // Only meaningful when the procedure's preparation_type is
            // mri_safety_screening (Part 12) — the checklist itself lives in
            // the request payload/audit log, not as individual boolean
            // columns, to avoid hard-coding a specific screening
            // questionnaire into the schema.
            $table->timestamp('mri_screening_completed_at')->nullable()->after('preparation_waived_reason');
            $table->foreignId('mri_screening_completed_by')->nullable()->after('mri_screening_completed_at')->constrained('users')->restrictOnDelete();
            $table->boolean('mri_screening_flagged')->default(false)->after('mri_screening_completed_by');
            $table->text('mri_screening_notes')->nullable()->after('mri_screening_flagged');

            // A review checkpoint only (Part 13) — never dose/allergy/
            // medication management.
            $table->timestamp('contrast_reviewed_at')->nullable()->after('mri_screening_notes');
            $table->foreignId('contrast_reviewed_by')->nullable()->after('contrast_reviewed_at')->constrained('users')->restrictOnDelete();

            $table->string('consent_status')->default('not_required')->after('contrast_reviewed_by');
            $table->timestamp('consent_recorded_at')->nullable()->after('consent_status');
            $table->foreignId('consent_recorded_by')->nullable()->after('consent_recorded_at')->constrained('users')->restrictOnDelete();

            $table->string('not_ready_reason')->nullable()->after('consent_recorded_by');

            $table->timestamp('ready_at')->nullable()->after('not_ready_reason');
            $table->foreignId('ready_by')->nullable()->after('ready_at')->constrained('users')->restrictOnDelete();

            $table->index('arrived_at');
            $table->index('ready_at');
            $table->index('mri_screening_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_appointments', function (Blueprint $table) {
            $table->dropColumn([
                'arrived_at', 'arrived_by',
                'verified_at', 'verified_by',
                'instructions_given_at', 'instructions_given_by',
                'preparation_completed_at', 'preparation_completed_by', 'preparation_notes',
                'preparation_waived_at', 'preparation_waived_by', 'preparation_waived_reason',
                'mri_screening_completed_at', 'mri_screening_completed_by', 'mri_screening_flagged', 'mri_screening_notes',
                'contrast_reviewed_at', 'contrast_reviewed_by',
                'consent_status', 'consent_recorded_at', 'consent_recorded_by',
                'not_ready_reason',
                'ready_at', 'ready_by',
            ]);
        });
    }
};
