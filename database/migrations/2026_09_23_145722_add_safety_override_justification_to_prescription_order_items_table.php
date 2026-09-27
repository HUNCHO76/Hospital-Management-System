<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the doctor's safety override justification to each prescription item.
 * When MedicationSafetyService detects a contraindication/caution rule match,
 * the prescriber must supply a justification — stored here so the pharmacist
 * can review the clinical reasoning during verification (spec §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_order_items', function (Blueprint $table) {
            $table->text('safety_override_justification')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_order_items', function (Blueprint $table) {
            $table->dropColumn('safety_override_justification');
        });
    }
};
