<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of NHIF member/card verification attempts for a
 * PatientInsurance record (Phase 8, Step 65). One row per attempt; the most
 * recent success is what PatientInsurance::latestNhifVerification() surfaces.
 *
 * identifier_type: 'nin' — lookup used the patient's national ID number.
 *                 'card_number' — lookup used the NHIF card number.
 *
 * verification_status: mirrors NhifVerificationStatus in patient-insurance.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_member_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->string('identifier_type');      // nin | card_number
            $table->string('identifier_value');
            $table->string('nhif_operation');        // NHIF API endpoint called
            $table->string('verification_status');   // verified | not_found | invalid | expired | inactive | error
            $table->string('nhif_member_name')->nullable();
            $table->string('nhif_card_no')->nullable();
            $table->string('nhif_expiry_date')->nullable();
            $table->string('nhif_membership_no')->nullable();
            $table->string('nhif_plan_name')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->string('correlation_id')->nullable();
            $table->json('request_data')->nullable();   // sanitized request (no credentials)
            $table->json('response_data')->nullable();  // raw NHIF response
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_insurance_id', 'verification_status']);
            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_member_verifications');
    }
};
