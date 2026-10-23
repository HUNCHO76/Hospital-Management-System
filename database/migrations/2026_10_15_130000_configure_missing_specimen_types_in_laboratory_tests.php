<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill missing specimen configurations for active LaboratoryTest records,
 * specifically targeting NHIF-5026 (ABG/VBG) and ensuring no active catalog
 * item has a null specimen_type.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Explicitly configure NHIF-5026 (ABG/VBG Analysis)
        DB::table('laboratory_tests')
            ->where('code', 'NHIF-5026')
            ->orWhere('nhif_code', '5026')
            ->update([
                'specimen_type' => 'whole_blood',
                'specimen_container' => 'other',
                'collection_notes' => 'Heparinized blood gas syringe',
            ]);

        // 2. Ensure any other active test with null or empty specimen_type has safe non-null defaults
        DB::table('laboratory_tests')
            ->where(function ($query) {
                $query->whereNull('specimen_type')
                    ->orWhere('specimen_type', '');
            })
            ->update([
                'specimen_type' => 'other',
                'specimen_container' => DB::raw("COALESCE(specimen_container, 'other')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback: preserving configured specimen requirements is non-destructive
    }
};
