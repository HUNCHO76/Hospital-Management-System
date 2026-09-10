<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The laboratory-processing entity (Phase 4, Step 32) — the bench-side
 * lifecycle of a single requested test once its specimen has been
 * received. Belongs to both the specimen (`laboratory_sample_id`) and the
 * specific test (`laboratory_order_item_id`) directly rather than only
 * through the sample<->item pivot, since a processing record is inherently
 * "this test, on this particular specimen" — the pivot models which tests
 * a specimen satisfies in general, not the processing of any one of them.
 *
 * No patient_id/order_number/test_name/specimen_type is duplicated here
 * (spec §5/§29) — all reachable through laboratory_sample_id /
 * laboratory_order_item_id, the authoritative relationships. Rejection
 * reason/actor/timestamp also stay solely on `laboratory_samples` (Step
 * 31) rather than being duplicated here — rejecting a specimen during
 * processing is still fundamentally a specimen-level fact, reusing that
 * exact column set (spec §18's explicit reuse instruction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_processing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_sample_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_order_item_id')->constrained()->restrictOnDelete();

            $table->string('status')->default('received');

            $table->foreignId('bench_id')->nullable()->constrained('laboratory_benches')->restrictOnDelete();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->text('processing_notes')->nullable();

            $table->timestamps();

            // A given specimen can only ever have one processing record per
            // test it satisfies (spec's own "duplicate processing" validation
            // requirement) — a rejected specimen's recollection gets a brand
            // new laboratory_sample_id, so this never blocks a legitimate
            // re-attempt.
            $table->unique(['laboratory_sample_id', 'laboratory_order_item_id'], 'lab_processing_sample_item_unique');
            $table->index('laboratory_order_item_id');
            $table->index('status');
            $table->index('assigned_to');
            $table->index('bench_id');
            $table->index('started_at');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_processing');
    }
};
