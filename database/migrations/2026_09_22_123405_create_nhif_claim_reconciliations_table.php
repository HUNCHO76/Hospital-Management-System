<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIF claim reconciliation records — one per reconciliation event (Phase 8, Step 74).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('nhif_claim_submission_attempt_id')->nullable();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('source', 30);                  // api_poll | manual | webhook
            $table->string('response_reference');
            $table->string('nhif_reference')->nullable();
            $table->string('nhif_raw_status')->nullable();
            $table->string('nhif_response_code')->nullable();
            $table->string('hms_status', 30);
            $table->boolean('amounts_confirmed')->default(false);
            $table->decimal('submitted_amount', 14, 2)->nullable();
            $table->decimal('nhif_approved_amount', 14, 2)->nullable();
            $table->decimal('nhif_rejected_amount', 14, 2)->nullable();
            $table->decimal('nhif_adjusted_amount', 14, 2)->nullable();
            $table->decimal('patient_responsibility_expected', 14, 2)->nullable();
            $table->decimal('patient_responsibility_nhif', 14, 2)->nullable();
            $table->decimal('variance_amount', 14, 2)->nullable();
            $table->string('variance_category', 30)->nullable();
            $table->string('reconciliation_status', 30);
            $table->longText('raw_response')->nullable();
            $table->longText('normalized_response')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['nhif_claim_id', 'reconciliation_status'], 'nhif_claim_recon_claim_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_reconciliations');
    }
};
