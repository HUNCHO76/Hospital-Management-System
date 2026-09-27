<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each attempt to submit an NHIF claim to the OCS endpoint (Phase 8, Step 73).
 * Idempotent submission key prevents duplicate submission on retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('submission_key')->unique();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('submission_status', 30);
            $table->string('nhif_response_status')->nullable();
            $table->string('nhif_claim_reference')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('raw_response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['nhif_claim_id', 'submission_status'], 'nhif_claim_sub_attempt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_submission_attempts');
    }
};
