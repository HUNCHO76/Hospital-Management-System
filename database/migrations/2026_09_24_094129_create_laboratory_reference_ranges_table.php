<?php

use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured, age- and sex-specific reference ranges per test parameter (Batch A).
 *
 * Each row defines one band: a sex (male / female / any) × an age window
 * [age_min_days, age_max_days] (both nullable = unbounded). Multiple rows
 * for the same parameter implement pediatric vs. adult vs. neonatal bands.
 *
 * At result-entry time, LaboratoryReferenceRangeService selects the most
 * specific matching row (sex-specific wins over 'any'; narrower age band
 * wins over wider), then snapshots low/high/critical into laboratory_result_values
 * so the snapshot is immutable after release even if catalog data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_reference_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LaboratoryTestParameter::class)->constrained()->cascadeOnDelete();

            $table->string('sex', 10)->default('any');

            /**
             * Age bounds in whole days (more precise than years/months for
             * neonatal/paediatric bands). NULL = unbounded on that side.
             * e.g. neonates: age_min_days=0, age_max_days=27
             *      adults:   age_min_days=6570 (18 yrs), age_max_days=null
             */
            $table->unsignedInteger('age_min_days')->nullable();
            $table->unsignedInteger('age_max_days')->nullable();

            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('critical_low', 12, 4)->nullable();
            $table->decimal('critical_high', 12, 4)->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['laboratory_test_parameter_id', 'sex'], 'lrr_param_sex_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_reference_ranges');
    }
};
