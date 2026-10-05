<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw NHIF GetPricePackage catalog — unit prices per item per scheme/package.
 * Distinct from nhif_service_prices (internal allowable tariffs) and
 * nhif_scheme_services (eligibility/coverage catalog). This table stores
 * the verbatim NHIF price list so RequestServices can send the correct
 * unit_price without trusting client-submitted values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nhif_package_prices', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 50);
            $table->string('price_code', 50)->nullable();
            $table->unsignedInteger('item_type_id')->nullable();
            $table->string('item_name', 255)->nullable();
            $table->string('strength', 100)->nullable();
            $table->string('dosage', 100)->nullable();
            $table->unsignedInteger('package_id')->nullable();
            $table->unsignedInteger('scheme_id')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->boolean('is_restricted')->default(false);
            $table->boolean('is_over_the_counter')->default(false);
            $table->unsignedInteger('maximum_quantity')->nullable();
            $table->unsignedInteger('maximum_quantity_out_patient')->nullable();
            $table->unsignedInteger('maximum_quantity_in_patient')->nullable();
            $table->boolean('has_co_payment')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['item_code', 'scheme_id', 'package_id'], 'nhif_pkg_prices_code_scheme_pkg_unique');
            $table->index('item_code');
            $table->index('scheme_id');
            $table->index('package_id');
            $table->index('price_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhif_package_prices');
    }
};
