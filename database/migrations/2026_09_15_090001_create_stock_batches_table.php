<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single received lot of a StockItem (Phase 6, Step 49) — expiry, cost, and
 * the physical quantity all live here, never on StockItem (spec §5-6).
 *
 * `batch_number` preserves the manufacturer/supplier's own lot number as-is
 * (spec §7) — never replaced with an HMS-generated identifier; uniqueness is
 * scoped to `stock_item_id` only, since the same batch number may legitimately
 * be reused by a different manufacturer for a different medication.
 * Restocking the same physical batch (same stock_item_id + batch_number) adds
 * a RECEIPT transaction against this existing row rather than creating a
 * duplicate batch (spec §13) — see StockService::receive().
 *
 * `expiry_date` is required, not nullable: every medication this catalog can
 * hold is a real pharmaceutical (spec §8's "every batch that requires expiry
 * tracking" — this system deliberately never introduces a non-expiring stock
 * category), so making it optional would only invite the exact "expired stock
 * silently treated as dispensable" bug spec §16 warns against.
 *
 * No `reserved_quantity`/`available_quantity` split (spec §18): this system's
 * dispensing workflow deducts stock synchronously inside
 * DispensingService::complete()'s own transaction — there is no separate
 * "hold now, dispense later" step to reserve against, so a reservation column
 * would never be written to. `quantity` is simply "physically present and not
 * yet written off," and availability for dispensing is `status = 'active' AND
 * expiry_date >= today AND quantity > 0`, computed at query time.
 *
 * `status` values: active, quarantined, expired, damaged, depleted (spec §9,
 * DEPLETED trimmed down from spec's own six-value list is not needed —
 * 'depleted' *is* kept here, since it is a genuinely useful "why is this at
 * zero" distinction from expired/damaged, always set atomically by
 * StockService within the same transaction that zeroes the quantity, never a
 * background sync). No `unit_cost` history table: the batch row itself IS the
 * historical cost record (spec §29) — each batch's own `unit_cost` is set once
 * at first receipt and never overwritten by a later purchase price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->string('batch_number');
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date');
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['stock_item_id', 'batch_number']);
            $table->index('expiry_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
