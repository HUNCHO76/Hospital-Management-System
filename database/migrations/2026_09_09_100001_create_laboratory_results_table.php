<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (order item) — Phase 4's laboratory test catalog (Step 29)
     * is flat, with no panel/analyte/component structure for a single test
     * to decompose into (confirmed by audit before this migration was
     * written), so "one test, one result" is the correct shape here, not a
     * simplification of something richer that already exists. `result_type`/
     * `unit`/`reference_range`/`result_options` are snapshotted from the
     * catalog at creation time (mirroring LaboratoryOrderItem's own
     * test_code/test_name snapshot) because LaboratoryTestService::update()
     * allows those configuration fields to change even after a test has been
     * referenced — without a snapshot, editing a test's configuration later
     * could retroactively reinterpret an already-entered historical result.
     */
    public function up(): void
    {
        Schema::create('laboratory_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_order_item_id')->unique()->constrained('laboratory_order_items')->restrictOnDelete();
            $table->foreignId('laboratory_sample_id')->constrained('laboratory_samples')->restrictOnDelete();
            $table->foreignId('laboratory_test_id')->constrained('laboratory_tests')->restrictOnDelete();
            $table->string('status')->default('result_pending');

            // Snapshotted result-configuration (see class docblock above).
            $table->string('result_type')->nullable();
            $table->string('unit')->nullable();
            $table->text('reference_range')->nullable();
            $table->json('result_options')->nullable();

            // The result itself — exactly one of these three is populated,
            // depending on `result_type` (spec §5, "one test can have
            // different result types" — never force every test into the
            // same column).
            $table->decimal('value_numeric', 12, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->string('value_code')->nullable();

            // Interpretation is kept separate from the raw value (spec §18)
            // — never encoded into value_text/value_code itself.
            $table->string('abnormal_flag')->nullable();
            $table->text('result_comment')->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('amended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('amended_at')->nullable();

            $table->timestamps();

            $table->index('laboratory_sample_id');
            $table->index('laboratory_test_id');
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratory_results');
    }
};
