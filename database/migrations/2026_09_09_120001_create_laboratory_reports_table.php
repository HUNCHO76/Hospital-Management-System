<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per released VERSION of a laboratory order's report (Phase 4,
     * Step 35) — not per individual LaboratoryResult, since one order may
     * carry several independently-released tests (spec §12-13's own "never
     * mix results from different samples" example) that are naturally
     * presented together as one document. `results_snapshot` freezes the
     * released value/interpretation/verification/authorization metadata for
     * every currently-released result on the order at the moment this
     * version was generated — a JSON snapshot (mirroring the catalog's own
     * `result_options` and AuditLog's own old_values/new_values precedent)
     * rather than a second relational table, since it is never queried
     * relationally, only ever displayed as-is. This is the sole new table
     * this step introduces — release actor/timestamp themselves live
     * directly on `laboratory_results` (see the sibling migration),
     * mirroring how verified_by/authorized_by already live there.
     */
    public function up(): void
    {
        Schema::create('laboratory_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_order_id')->constrained('laboratory_orders')->restrictOnDelete();
            $table->string('report_number')->unique();
            $table->unsignedInteger('version');
            $table->string('status')->default('active');
            $table->json('results_snapshot');

            $table->foreignId('released_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at');

            $table->foreignId('printed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('downloaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamps();

            $table->unique(['laboratory_order_id', 'version']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratory_reports');
    }
};
