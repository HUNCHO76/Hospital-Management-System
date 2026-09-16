<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A financial adjustment directly against an invoice's patient outstanding
 * balance (Phase 7, Step 58) — neither a payment nor a correction of one,
 * but a deliberate, approved change to the amount the patient owes.
 *
 * `type`: the business reason for the adjustment (credit_adjustment,
 * debit_adjustment, approved_discount, write_off, billing_correction).
 * `direction`: 'credit' (reduces patient outstanding) or 'debit' (increases
 * it). The direction is always explicit in the stored row so downstream
 * queries never have to infer it from the type — credits and debits are
 * summed separately by PaymentService::calculateOutstanding() and exposed
 * as credit_adjustments / debit_adjustments in the breakdown.
 *
 * `amount` is always positive; the direction determines its effect. No
 * negative amounts are accepted (ValidatesMoney enforces this at the service
 * layer, mirroring PaymentService::createPayment()'s own behavior).
 *
 * An adjustment is posted immediately — mirrors a payment's own "already
 * happened" immediacy. The original invoice amount (total_amount) is never
 * rewritten; calculateOutstanding() reads adjustments live on every call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->decimal('amount', 10, 2);
            $table->string('currency');
            $table->text('reason');
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('adjusted_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id']);
            $table->index(['hospital_id', 'invoice_id'], 'billing_adjustments_hospital_invoice_idx');
            $table->index(['patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_adjustments');
    }
};
