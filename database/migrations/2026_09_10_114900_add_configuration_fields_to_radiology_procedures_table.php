<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grows Step 36's minimal `radiology_procedures` stub into the full
 * configurable catalog (Phase 5, Step 37) — mirrors exactly how Step 29's
 * own `add_configuration_fields_to_laboratory_tests_table` migration grew
 * `laboratory_tests` out. No backfill/data-migration is needed here (unlike
 * Step 29's own migration): the table is brand new from Step 36 with only
 * 12 seeder-owned rows and no order has ever referenced one yet, so a plain
 * additive migration is safe.
 *
 * Deliberately no separate `category` column: Part 6's MODALITY value list
 * (XRAY/ULTRASOUND/CT/MRI/MAMMOGRAPHY/FLUOROSCOPY/OTHER) and Part 7's
 * CATEGORY value list (X_RAY/ULTRASOUND/CT/MRI/MAMMOGRAPHY/FLUOROSCOPY/
 * OTHER) are the same clinical dimension under two names — storing both
 * would duplicate the same fact in two columns that could silently drift
 * out of sync. The existing `modality` column (already added in Step 36)
 * serves both the "Category" filter and the "Modality" column the catalog
 * UI mockups show.
 *
 * `body_sites` is plural/JSON rather than a single `body_site` string:
 * Part 8 explicitly warns "do not assume every imaging procedure has only
 * one body site" (e.g. `CT-CHEST-ABD` spans two regions) — mirrors
 * `laboratory_tests.result_options`'s own existing JSON-array-cast
 * precedent rather than introducing a new many-to-many pivot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_procedures', function (Blueprint $table) {
            $table->json('body_sites')->nullable()->after('modality');
            $table->string('laterality')->default('not_applicable')->after('body_sites');
            $table->string('contrast_requirement')->default('no_contrast')->after('laterality');
            $table->string('contrast_type')->nullable()->after('contrast_requirement');
            $table->string('preparation_type')->default('none')->after('contrast_type');
            $table->text('preparation_instructions')->nullable()->after('preparation_type');
            $table->unsignedInteger('turnaround_time_minutes')->nullable()->after('preparation_instructions');
            $table->unsignedInteger('sort_order')->default(0)->after('turnaround_time_minutes');
            $table->foreignId('created_by')->nullable()->after('sort_order')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();

            $table->index('modality');
            $table->index('laterality');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_procedures', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'body_sites', 'laterality', 'contrast_requirement', 'contrast_type',
                'preparation_type', 'preparation_instructions', 'turnaround_time_minutes',
                'sort_order', 'created_by', 'updated_by',
            ]);
        });
    }
};
