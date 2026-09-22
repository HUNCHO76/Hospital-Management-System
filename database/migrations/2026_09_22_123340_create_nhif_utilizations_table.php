<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF clinical service utilization records — one per performed service item (Phase 8, Step 71).
 * Idempotency key prevents duplicate submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_utilizations', function (Blueprint $table) {
            $table->id();
            $table->string('utilization_number')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_insurance_id')->constrained()->restrictOnDelete();
            $table->foreignId('clinical_encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nhif_service_mapping_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nhif_preauthorization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module');                      // lab | radiology | pharmacy | consultation | procedure
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('service_instance_type');
            $table->unsignedBigInteger('service_instance_id');
            $table->string('nhif_item_code')->nullable();
            $table->string('nhif_item_name')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('hms_unit_price', 10, 2);
            $table->decimal('hms_amount', 10, 2);
            $table->decimal('nhif_allowable_unit_price', 10, 2)->nullable();
            $table->decimal('nhif_approved_amount', 10, 2)->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->timestamp('service_date');
            $table->string('nhif_authorization_no')->nullable();
            $table->string('status');
            $table->string('pending_reason')->nullable();
            $table->boolean('outcome_uncertain')->default(false);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('nhif_reference')->nullable();
            $table->string('nhif_status_code')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->longText('raw_request')->nullable();
            $table->longText('raw_response')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['patient_insurance_id', 'status']);
            $table->index(['hospital_id', 'module', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_utilizations');
    }
};
