<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_claims', function (Blueprint $table) {
            $table->string('bill_no')->nullable()->after('folio_no');
            $table->tinyInteger('visit_type_id')->nullable()->after('discharged_at')->comment('1=OPD, 2=IPD');
            $table->string('patient_type_code')->nullable()->after('visit_type_id');
            $table->text('clinical_notes')->nullable()->after('patient_type_code');
            $table->string('late_submission_reason')->nullable()->after('clinical_notes');
            $table->string('confirmation_code')->nullable()->after('authorization_no');
            $table->json('attending_practitioners')->nullable()->after('nhif_practitioner_no');
            // Signing
            $table->timestamp('signed_at')->nullable()->after('snapshot_hash');
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete()->after('signed_at');
            $table->string('signature_method')->nullable()->after('signed_by');
            $table->json('signature_payload')->nullable()->after('signature_method');
            $table->json('signature_response')->nullable()->after('signature_payload');
            // Submission response fields from ReceivedClaim
            $table->string('nhif_submission_no')->nullable()->after('nhif_claim_reference');
            $table->string('nhif_hash_code')->nullable()->after('nhif_submission_no');
            $table->text('nhif_submission_remarks')->nullable()->after('nhif_hash_code');
            // Submission actor
            $table->timestamp('submitted_at')->nullable()->after('ready_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('nhif_claims', function (Blueprint $table) {
            $table->dropColumn([
                'bill_no', 'visit_type_id', 'patient_type_code', 'clinical_notes',
                'late_submission_reason', 'confirmation_code', 'attending_practitioners',
                'signed_at', 'signed_by', 'signature_method', 'signature_payload',
                'signature_response', 'nhif_submission_no', 'nhif_hash_code',
                'nhif_submission_remarks', 'submitted_at', 'submitted_by',
            ]);
        });
    }
};
