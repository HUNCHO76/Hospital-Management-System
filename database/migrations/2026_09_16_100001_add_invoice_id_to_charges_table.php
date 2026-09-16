<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Charge to the Invoice it was included in (Phase 7, Step 55) — see
 * create_invoices_table's own docblock for why this single nullable column
 * is the whole "invoice item" relationship, rather than a second table with
 * its own copied financial fields.
 *
 * Nullable: a charge starts eligible-but-unlinked (`invoice_id IS NULL`)
 * and is only ever linked while being added to a still-DRAFT invoice
 * (`InvoiceService::addCharge()`, under a row lock re-checking this exact
 * column is still null — the database-adjacent half of spec §20's
 * duplicate-invoicing protection). Voiding/cancelling the invoice sets it
 * back to null (`InvoiceService::void()`/`cancel()`), freeing the charge
 * for a future corrected invoice — spec §13's own "Void/Cancel -> Corrected
 * Charges -> New Invoice" workflow, satisfied without ever touching the
 * charge's own status.
 *
 * No unique constraint: many charges legitimately point to the same one
 * invoice (that is the whole relationship) — the constraint this step
 * actually needs is "this charge's own `invoice_id` is null before it can
 * be claimed," which is a WHERE clause under a row lock, not a table-level
 * uniqueness rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('patient_account_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
