<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Update LaboratoryTest entries that have a null or empty specimen_type.
 * Sets a default of 'other' (a valid value in LaboratoryTest::SPECIMEN_TYPES).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update rows where specimen_type is missing.
        DB::table('laboratory_tests')
            ->whereNull('specimen_type')
            ->orWhere('specimen_type', '')
            ->update([
                'specimen_type' => 'other',
                // Preserve existing container if present; otherwise default to 'other'.
                'specimen_container' => DB::raw("COALESCE(specimen_container, 'other')"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the default back to null for records we modified.
        DB::table('laboratory_tests')
            ->where('specimen_type', 'other')
            ->update([
                'specimen_type' => null,
            ]);
    }
};
?>
