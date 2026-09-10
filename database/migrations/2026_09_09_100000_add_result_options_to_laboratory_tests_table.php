<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `qualitative`/`enum` result types (Step 29's own RESULT_TYPES)
     * need a per-test configured choice list (Phase 4, Step 33, spec §14-15
     * — "do not hard-code blood groups directly into the generic result
     * engine"); nothing in the schema carries one yet. A single nullable
     * JSON array is the minimal structure this needs — no separate lookup
     * table, matching the catalog's own specimen_type/specimen_container
     * precedent of a plain configured list rather than a full vocabulary
     * table.
     */
    public function up(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->json('result_options')->nullable()->after('reference_range');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->dropColumn('result_options');
        });
    }
};
