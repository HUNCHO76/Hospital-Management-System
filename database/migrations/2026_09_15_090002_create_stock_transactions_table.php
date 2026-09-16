<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only stock movement ledger (Phase 6, Step 49, spec §10-11) — the
 * single source of truth for every quantity change a StockBatch has ever had.
 * `stock_batches.quantity` is a maintained running total for fast reads, but
 * this table is what an investigation, NHIF claim, or reconciliation actually
 * trusts (spec §47): `balance_before`/`balance_after` on every row let the
 * whole history be replayed and checked against the batch's current quantity.
 *
 * Never updated or deleted after insert (spec §11) — StockService never
 * mutates an existing row; a mistaken transaction is corrected with a new,
 * opposite-direction transaction, never a rewrite. No `updated_at` column,
 * deliberately, as a structural reminder of that invariant.
 *
 * `transaction_number` (mirrors Dispensing::dispensing_number/
 * PrescriptionOrder::prescription_number) is this ledger row's own
 * traceability reference (spec §26) — this step does not build a separate
 * GoodsReceipt/Adjustment entity (spec §12's "do not create a complete
 * procurement system"), so the transaction itself is the receipt/adjustment
 * record referenced elsewhere. `reference_type`/`reference_id` additionally
 * point a DISPENSE transaction back at the specific DispensingItem it
 * fulfilled (Step 48 integration, spec §14) — a plain descriptive pointer, not
 * a real FK, mirroring AuditLog's own auditable_type/auditable_id shape,
 * since nothing else in this ledger needs to reference more than one kind of
 * source record.
 *
 * No TRANSFER_IN/TRANSFER_OUT type: this system has no multi-location pharmacy
 * architecture to transfer between (see create_stock_items_table's own
 * docblock) — spec §21 itself only asks for transfers "where appropriate",
 * and inventing a transfer workflow with no second location to transfer to
 * would be unreachable, untestable code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number')->unique();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->string('transaction_type');
            $table->integer('quantity');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index('stock_item_id');
            $table->index('stock_batch_id');
            $table->index('transaction_type');
            $table->index('occurred_at');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
