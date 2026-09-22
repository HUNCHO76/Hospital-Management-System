<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line-item reconciliation within a claim reconciliation event (Phase 8, Step 74).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_item_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('nhif_claim_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('nhif_utilization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 20);
            $table->string('nhif_item_code')->nullable();
            $table->boolean('amounts_confirmed')->default(false);
            $table->decimal('submitted_amount', 14, 2)->nullable();
            $table->decimal('nhif_tariff_amount', 14, 2)->nullable();
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->decimal('rejected_amount', 14, 2)->nullable();
            $table->decimal('adjusted_amount', 14, 2)->nullable();
            $table->string('nhif_item_status', 30)->nullable();
            $table->string('nhif_raw_item_status')->nullable();
            $table->string('rejection_code')->nullable();
            $table->text('rejection_message')->nullable();
            $table->decimal('variance_amount', 14, 2)->nullable();
            $table->string('variance_category', 30)->nullable();
            $table->timestamps();

            $table->index(['nhif_claim_reconciliation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_item_reconciliations');
    }
};
