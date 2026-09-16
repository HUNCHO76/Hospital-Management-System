<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single pharmacy dispensing event against a VERIFIED PrescriptionOrder
 * (Phase 6, Step 48) — the actual fulfillment of a prescription, entirely
 * separate from the clinical order (Step 46) and its verification review
 * (Step 47). A prescription may be dispensed across several separate events
 * (partial dispensing, spec §10-11), so this is deliberately 1:many off
 * PrescriptionOrder, never a status column retrofitted onto it — mirrors
 * PrescriptionVerification's own "genuinely separate entity" precedent.
 * Only one IN_PROGRESS dispensing may exist per order at a time (enforced by
 * DispensingService::start(), row-locking the order), which is what
 * guarantees the total dispensed quantity can never exceed what was
 * prescribed (spec §19) without needing per-request quantity locking beyond
 * that single active session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_order_id')->constrained()->restrictOnDelete();
            $table->string('dispensing_number')->unique();
            $table->string('status')->default('in_progress');
            $table->foreignId('dispensed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('prescription_order_id');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensings');
    }
};
