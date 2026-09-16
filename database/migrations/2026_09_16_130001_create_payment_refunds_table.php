<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partial or full refund against a posted payment (Phase 7, Step 58).
 * Unlike a reversal (which negates the whole payment in one shot), a refund
 * can be partial — "patient paid 100k, we refunded 30k, net 70k paid".
 *
 * Multiple refunds per payment are allowed; PaymentService::
 * calculateOutstanding() sums all posted refunds against a payment's
 * invoice and includes them in refunds_total. The original payment is
 * never modified.
 *
 * The UNIQUE constraint mirrors Payment's own duplicate-reference protection:
 * two refunds for the same traceable method (card/bank_transfer/mobile_money)
 * cannot share a reference number at the same hospital. NULL refund_reference
 * (cash refunds) are unrestricted — SQL treats NULL ≠ NULL in UNIQUE indexes.
 *
 * `refund_method`/`refund_reference`: same vocabulary as the original payment
 * methods, since a refund is typically returned via the same channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->string('refund_method');
            $table->string('refund_reference')->nullable();
            $table->text('reason');
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index(['hospital_id', 'invoice_id'], 'payment_refunds_hospital_invoice_idx');
            $table->index(['patient_id']);
            $table->unique(
                ['hospital_id', 'refund_method', 'refund_reference'],
                'payment_refunds_hospital_method_reference_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
