<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add specimen_source column to laboratory_order_items, laboratory_samples,
 * and laboratory_reference_ranges to support specimen differentiation
 * (e.g. Arterial vs Venous blood gases).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_order_items', function (Blueprint $table) {
            $table->string('specimen_source', 30)->nullable()->after('clinical_note');
        });

        Schema::table('laboratory_samples', function (Blueprint $table) {
            $table->string('specimen_source', 30)->nullable()->after('container_type');
        });

        Schema::table('laboratory_reference_ranges', function (Blueprint $table) {
            $table->string('specimen_source', 30)->nullable()->after('sex');
            $table->index(['laboratory_test_parameter_id', 'specimen_source', 'sex'], 'lrr_param_source_sex_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_reference_ranges', function (Blueprint $table) {
            $table->dropIndex('lrr_param_source_sex_index');
            $table->dropColumn('specimen_source');
        });

        Schema::table('laboratory_samples', function (Blueprint $table) {
            $table->dropColumn('specimen_source');
        });

        Schema::table('laboratory_order_items', function (Blueprint $table) {
            $table->dropColumn('specimen_source');
        });
    }
};
