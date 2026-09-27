<?php

use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-analyte parameter definitions for a laboratory test (Batch A).
 *
 * A single-analyte test (e.g. Hemoglobin) has one parameter row; a
 * multi-component test (e.g. Urinalysis) has many. Every parameter carries
 * its own unit, data_type, display order, and optional calculator code so
 * result entry and flag calculation can be driven entirely from this table
 * rather than from hard-coded field lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_test_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LaboratoryTest::class)->constrained()->cascadeOnDelete();

            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('unit', 50)->nullable();

            /**
             * How the result value for this parameter is stored/rendered:
             *  numeric  – floating-point number (value_numeric used)
             *  text     – free-text narrative (value_text used)
             *  option   – one value from options_json list (value_code used)
             */
            $table->string('data_type', 20)->default('numeric');

            $table->unsignedTinyInteger('decimal_places')->default(1);

            /**
             * JSON array of allowed values when data_type = 'option',
             * e.g. ["positive","negative"] or ["A","B","AB","O"].
             */
            $table->json('options_json')->nullable();

            /**
             * JSON array of option values considered abnormal, used to
             * set abnormal_flag = 'abnormal' for option-type parameters.
             * e.g. ["positive"] for a Malaria RDT parameter.
             */
            $table->json('abnormal_options')->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);

            /**
             * Whether this parameter's value is computed from other
             * parameters on the same result (e.g. indirect bilirubin,
             * NLR, eGFR). The formula references sibling parameter codes
             * by name and is evaluated by LaboratoryFlagCalculator.
             */
            $table->boolean('is_calculated')->default(false);
            $table->string('calculation_formula', 500)->nullable();

            /**
             * Analyzer instrument code for HL7/ASTM result import mapping.
             * Allows the same parameter to be addressed by the instrument's
             * own code rather than the catalog code.
             */
            $table->string('analyser_code', 50)->nullable();
            $table->string('loinc_code', 20)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['laboratory_test_id', 'code'], 'ltp_test_code_unique');
            $table->index(['laboratory_test_id', 'display_order'], 'ltp_test_display_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_test_parameters');
    }
};
