<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The complete allocation history of a payment against an invoice (Phase 7,
 * Step 57, spec §57.10/§57.11) — kept as its own table, separate from
 * `payments` itself, even though every payment in this step's own scope
 * produces exactly one allocation row (its own `invoice_id`, always).
 *
 * This separation exists purely so a future step can let one payment span
 * multiple invoices (or an invoice receive contributions tracked
 * line-by-line) without a schema change — spec's own explicit "structure
 * the service so future extension is possible." `PaymentService` enforces
 * the invariant `SUM(allocations.amount for this payment) === payment.amount`
 * directly (never assumed from the schema alone).
 *
 * `hospital_id`/`patient_id` are denormalized from the payment/invoice at
 * creation time (mirrors `Charge`'s own denormalized `hospital_id`/
 * `patient_id` off its account) purely for scoping/index performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index(['invoice_id']);
            $table->index(['hospital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
