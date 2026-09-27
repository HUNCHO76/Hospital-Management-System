<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only NHIF eligibility check history for a PatientInsurance record
 * (Phase 8, Step 66). One row per check; PatientInsurance caches the latest
 * outcome in nhif_eligibility_status for fast read access.
 *
 * eligibility_status: mirrors NhifEligibilityStatus in patient-insurance.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_eligibility_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->string('card_no');
            $table->string('nhif_operation');
            $table->string('eligibility_status');   // eligible | ineligible | expired | suspended | unknown | error
            $table->decimal('nhif_coverage_percent', 5, 2)->nullable();
            $table->decimal('nhif_patient_responsibility_percent', 5, 2)->nullable();
            $table->date('nhif_valid_from')->nullable();
            $table->date('nhif_valid_to')->nullable();
            $table->text('nhif_remarks')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->string('correlation_id')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamp('eligibility_checked_at');
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_insurance_id', 'eligibility_status'], 'nhif_elig_insurance_status_idx');
            $table->index('eligibility_checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_eligibility_records');
    }
};
