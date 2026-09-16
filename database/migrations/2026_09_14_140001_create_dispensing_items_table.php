<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fulfillment of a single PrescriptionOrderItem within one Dispensing
 * event (Phase 6, Step 48). `dispensed_quantity` is this event's own
 * contribution only, never a running total — the prescribed vs. dispensed
 * comparison (and the "remaining" calculation, spec §9) is always derived by
 * summing every non-cancelled DispensingItem row for a given
 * prescription_order_item_id, so history is never overwritten (spec §11).
 * No medication_id/snapshot columns here: PrescriptionOrderItem already owns
 * the immutable prescribing-time snapshot (Step 46); re-copying it here
 * would be redundant, unjustified duplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispensing_id')->constrained()->restrictOnDelete();
            $table->foreignId('prescription_order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('dispensed_quantity')->default(0);
            $table->string('status')->default('dispensed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['dispensing_id', 'prescription_order_item_id']);
            $table->index('prescription_order_item_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensing_items');
    }
};
