<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups a patient account's eligible Charges into a formal financial
 * document (Phase 7, Step 55). See docs/invoices.md for the full audit —
 * `Charge`/`ChargeService` (Step 52/54), `PatientAccount` (Step 52), and
 * `Service`/`ServicePrice` (Step 53) were all found already authoritative;
 * this migration is a pure addition on top, never a duplicate/competing
 * financial structure.
 *
 * No separate `invoice_items` table (spec §8's own illustrative shape):
 * `Charge` already carries every field an invoice line item would need
 * (`service_id`, `service_code_snapshot`, `description` as name snapshot,
 * `quantity`, `unit_price`, `gross_amount`, `discount_amount`,
 * `net_amount`, `currency`) — copying them into a second table would be
 * exactly the "duplicated financial values that can silently diverge" spec
 * §41 itself warns against, and would violate the "reuse, fix, and extend"
 * mandate (spec §2). Instead, `add_invoice_id_to_charges_table` (this same
 * migration set) adds one nullable `invoice_id` FK directly on `charges` —
 * a charge IS the invoice line item once linked. This single column is
 * also spec §31's own "one authoritative mechanism" for charge-invoiced
 * state: no competing `is_billed`/`is_invoiced`/`invoice_status` boolean
 * anywhere — a charge is invoiced if and only if `invoice_id` is not null.
 *
 * `hospital_id`/`patient_id` are denormalized directly onto the invoice
 * (rather than read only through `patient_account_id`) for the exact same
 * reason `charges` already denormalizes them from `patient_account_id` —
 * direct, indexable querying, never a second source of truth (the account
 * remains the sole ownership relationship).
 *
 * No `due_date`/`tax_amount`/invoice-level `discount_amount` columns
 * (spec's own illustrative field list includes them): this codebase has no
 * existing tax concept anywhere, and a discount is already applied and
 * frozen per-charge (`Charge.discount_amount`, baked into
 * `Charge.net_amount`) — an invoice-level discount field would be a second,
 * competing discount mechanism with no consumer, exactly what spec §29
 * itself warns against inventing. `subtotal` and `total_amount` are kept as
 * two distinct columns (even though they are numerically identical today)
 * purely so a later step (insurance-adjusted portion, tax) can extend
 * without a schema change — not because they differ yet.
 *
 * `cancelled_at`/`cancelled_by`/`cancellation_reason` serve both the
 * VOIDED (a draft abandoned before ever becoming a financial document) and
 * CANCELLED (a posted invoice reversed after the fact) transitions,
 * mirroring `charges`' own single shared cancellation column set exactly
 * (distinguished by `status` alone, never a duplicate column pair per
 * transition).
 *
 * `posted_at` mirrors `charges.posted_at` exactly — no separate
 * `issued_at`/`issued_by` fields were introduced for the identical
 * "became a real financial fact" concept; the actor is recorded via the
 * shared `updated_by` column at that transition, exactly like
 * `ChargeService::post()` already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_account_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->date('invoice_date')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('currency');
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('hospital_id');
            $table->index('patient_id');
            $table->index('patient_account_id');
            $table->index('status');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
