<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add NHIF eligibility cache columns to patient_insurances (Phase 8, Step 66).
 * These cache the latest NHIF eligibility check result for fast read access —
 * the full audit history lives in nhif_eligibility_records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->string('nhif_eligibility_status')->nullable()->after('verified_at');
            $table->decimal('nhif_coverage_percent', 5, 2)->nullable()->after('nhif_eligibility_status');
            $table->timestamp('nhif_eligibility_checked_at')->nullable()->after('nhif_coverage_percent');
        });
    }

    public function down(): void
    {
        Schema::table('patient_insurances', function (Blueprint $table) {
            $table->dropColumn(['nhif_eligibility_status', 'nhif_coverage_percent', 'nhif_eligibility_checked_at']);
        });
    }
};
