<?php

use App\Models\LaboratoryResult;
use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-parameter result values for multi-component tests (Phase 4 Batch B).
 *
 * One row per (laboratory_result × laboratory_test_parameter) pair.
 * Single-analyte tests (HB, Na, etc.) that were created before parameters
 * existed continue to store their result in laboratory_results directly
 * (value_numeric/value_text/value_code) — this table is additive and only
 * used when a test has parameters configured.
 *
 * Reference range columns (reference_low/high/critical_*) are snapshotted at
 * save time so historical results remain correct even when catalog ranges are
 * later updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_result_values', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(LaboratoryResult::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(LaboratoryTestParameter::class)
                ->constrained()
                ->restrictOnDelete();

            // Typed value storage — exactly one is populated per row,
            // depending on the parameter's data_type.
            $table->decimal('value_numeric', 12, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->string('value_code', 255)->nullable();

            // Snapshot of parameter configuration at entry time.
            $table->string('unit', 50)->nullable();

            // Snapshot of the best-matching reference range at entry time,
            // stored so the historical flag remains interpretable even when
            // the catalog range later changes (spec §18 — immutable snapshot).
            $table->decimal('reference_low', 12, 4)->nullable();
            $table->decimal('reference_high', 12, 4)->nullable();
            $table->decimal('reference_critical_low', 12, 4)->nullable();
            $table->decimal('reference_critical_high', 12, 4)->nullable();

            $table->string('abnormal_flag', 20)->nullable();
            $table->text('result_comment')->nullable();

            $table->timestamps();

            $table->unique(
                ['laboratory_result_id', 'laboratory_test_parameter_id'],
                'lrv_result_param_unique',
            );

            $table->index('laboratory_result_id', 'lrv_result_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_values');
    }
};
