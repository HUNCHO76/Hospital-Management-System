<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A controlled reversal of a posted payment (Phase 7, Step 58) — the
 * original payment row is never modified; this new row is the auditable
 * correction event that negates its balance contribution.
 *
 * UNIQUE(original_payment_id): each payment may be reversed exactly once.
 * The original payment stays POSTED forever; PaymentService::
 * calculateOutstanding() sums reversal amounts and subtracts them from
 * the net balance (as reversals_total), so the original record's own
 * immutability is never violated.
 *
 * All identity fields (hospital_id, patient_id, invoice_id, amount,
 * currency) are copied from the original payment at creation time, so a
 * reversal row is self-contained even if the original payment row is
 * later archived. `amount` always equals the original payment's full
 * amount — a partial reversal is called a refund (payment_refunds table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->string('reversal_number')->unique();
            $table->foreignId('original_payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->text('reason');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'invoice_id'], 'payment_reversals_hospital_invoice_idx');
            $table->index(['patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
