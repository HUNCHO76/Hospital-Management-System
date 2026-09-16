<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's payment against one invoice's own outstanding patient
 * responsibility (Phase 7, Step 57) — see PaymentService's own docblock for
 * the full reasoning behind this shape.
 *
 * `invoice_id` is required and direct (not only reachable through
 * `payment_allocations`): unlike `Charge` (which can exist un-invoiced) or
 * `ChargeInsuranceAllocation` (one per charge), a Payment in this step
 * always targets exactly one invoice for its entire lifetime — there is no
 * "payment not yet applied to anything" concept here (spec §57.10's own
 * "if the existing architecture only supports one invoice per payment,
 * preserve that boundary"). `payment_allocations` still exists alongside it
 * (see that migration's own docblock) so a future step can split one
 * payment across multiple invoices without a schema change here.
 *
 * `receipt_number` is generated once, at creation, alongside
 * `payment_number` — a receipt is deliberately not a separate entity (spec
 * §57.17): it is the printable view of a POSTED payment, never
 * independently created/edited/reissued in this step, so a second table
 * would only duplicate values that can never diverge from this row.
 *
 * `status`: POSTED is reached directly by PaymentService::createPayment()
 * (mirrors StockService::receive()'s own "a real, immediate event, no
 * meaningful draft state" precedent — a payment represents money already in
 * hand, unlike Charge/Invoice's own build-then-finalize documents). FAILED
 * is the one controlled correction this step provides (mirrors
 * ChargeService::cancel()'s "minimal correction mechanism, not a full
 * Adjustment entity" — genuine reversal/refund is Step 58's job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->string('receipt_number')->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->string('payment_method');
            $table->string('reference_number')->nullable();
            $table->string('status')->default('posted');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('failed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
            $table->index(['patient_id']);
            $table->index(['patient_account_id']);
            $table->index(['invoice_id']);
            // Two different bank/mobile-money transactions can never share a
            // reference under the same method at the same facility — the
            // database-enforced half of spec §57.15's duplicate-payment
            // protection (row-locked re-validation under the invoice lock is
            // the other half, see PaymentService::createPayment()). Multiple
            // NULLs (cash/other payments, which carry no reference) remain
            // unrestricted — every SQL engine this project targets treats
            // NULL as distinct from NULL in a UNIQUE index.
            $table->unique(['hospital_id', 'payment_method', 'reference_number'], 'payments_hospital_method_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
