<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF claim (folio) header — aggregates utilization into a claimable unit (Phase 8, Steps 72–73).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_type', 20);          // outpatient | inpatient
            $table->string('status', 30);
            $table->string('scope_key', 40)->nullable()->unique();
            $table->unsignedInteger('folio_no')->nullable();
            $table->unsignedSmallInteger('folio_year')->nullable();
            $table->unsignedTinyInteger('folio_month')->nullable();
            $table->string('nhif_claim_reference')->nullable();
            $table->string('nhif_status')->nullable();
            $table->string('reconciliation_status', 30)->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('card_no')->nullable();
            $table->string('facility_code')->nullable();
            $table->date('attendance_date')->nullable();
            $table->dateTime('admitted_at')->nullable();
            $table->dateTime('discharged_at')->nullable();
            $table->string('primary_diagnosis_code')->nullable();
            $table->text('primary_diagnosis_description')->nullable();
            $table->foreignId('practitioner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nhif_practitioner_no')->nullable();
            $table->string('authorization_no')->nullable();
            $table->char('currency', 3)->default('TZS');
            $table->decimal('hms_total', 14, 2)->default(0);
            $table->decimal('nhif_allowable_total', 14, 2)->default(0);
            $table->decimal('nhif_responsibility_total', 14, 2)->default(0);
            $table->decimal('nhif_approved_total', 14, 2)->nullable();
            $table->decimal('nhif_rejected_total', 14, 2)->nullable();
            $table->decimal('nhif_adjusted_total', 14, 2)->nullable();
            $table->decimal('patient_responsibility_total', 14, 2)->default(0);
            $table->decimal('patient_paid_total', 14, 2)->default(0);
            $table->decimal('patient_outstanding_total', 14, 2)->default(0);
            $table->longText('validation_summary')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedInteger('generation_count')->default(0);
            $table->timestamp('ready_at')->nullable();
            $table->foreignId('ready_by')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('snapshot')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index(['patient_insurance_id', 'status']);
            $table->index(['folio_year', 'folio_month', 'hospital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claims');
    }
};
