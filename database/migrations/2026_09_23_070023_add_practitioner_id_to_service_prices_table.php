<?php

use App\Models\Practitioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds practitioner-specific pricing to the service_prices table.
 * NULL practitioner_id = facility-wide price (the original behavior).
 * Non-null = price that applies only when that specific practitioner
 * performs the service. ServicePriceResolver prefers the practitioner-specific
 * price over the facility-wide fallback when resolving a consultation fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_prices', function (Blueprint $table) {
            $table->foreignId('practitioner_id')->nullable()->after('hospital_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_prices', function (Blueprint $table) {
            $table->dropForeignIdFor(Practitioner::class, 'practitioner_id');
            $table->dropColumn('practitioner_id');
        });
    }
};
