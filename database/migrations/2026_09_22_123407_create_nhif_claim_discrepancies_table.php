<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discrepancies surfaced during claim reconciliation, requiring review/resolution (Phase 8, Step 74).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_claim_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nhif_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('nhif_claim_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nhif_claim_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->text('description');
            $table->decimal('submitted_amount', 14, 2)->nullable();
            $table->decimal('nhif_amount', 14, 2)->nullable();
            $table->decimal('variance_amount', 14, 2)->nullable();
            $table->string('status', 30);
            $table->timestamp('opened_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['nhif_claim_id', 'status']);
            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_claim_discrepancies');
    }
};
