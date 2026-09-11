<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology report verification & authorization (Phase 5, Step 43). Extends
 * the existing `radiology_reports` row rather than creating a separate
 * verification entity (Part 16) — the report is a single mutable row whose
 * full change history (who wrote/updated/submitted/returned/resubmitted/
 * verified it, and when) is already answered by the existing AuditLogService
 * trail (see the Step 42 migration's own doc-comment on why no dedicated
 * version table exists), extended in this step with the new
 * returned/resubmitted/verified audit events. `verifying_practitioner_id`
 * mirrors `reporting_practitioner_id` exactly (a Practitioner, not a User) so
 * the self-verification rule (Part 5: reporting_practitioner_id !=
 * verifying_practitioner_id) compares like with like; `verified_by`/
 * `returned_by` are the acting User, mirroring `submitted_by`/`updated_by`'s
 * existing convention.
 *
 * `return_reason` + `return_comments` are never cleared once set — even
 * after the report is corrected and resubmitted, Part 12's "a returned
 * report must clearly show Returned/Returned By/Returned At/Return Reason"
 * remains visible as history rather than being wiped the moment the report
 * moves on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->foreignId('verifying_practitioner_id')->nullable()->after('reporting_practitioner_id')
                ->constrained('practitioners')->restrictOnDelete();

            $table->foreignId('verified_by')->nullable()->after('submitted_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('verification_notes')->nullable()->after('verified_at');

            $table->foreignId('returned_by')->nullable()->after('verification_notes')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->string('return_reason')->nullable()->after('returned_at');
            $table->text('return_comments')->nullable()->after('return_reason');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verifying_practitioner_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'verification_notes']);
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['returned_at', 'return_reason', 'return_comments']);
        });
    }
};
