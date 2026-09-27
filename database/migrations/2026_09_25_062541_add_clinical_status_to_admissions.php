<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 74: Add clinical_status to track the patient's current clinical
 * condition during admission — distinct from the workflow status column
 * (pending/active/discharged) which tracks administrative lifecycle.
 * NULL = not yet assessed (safe default: no clinical_status assessment
 * has been recorded by a clinician yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->string('clinical_status')->nullable()->after('status');
            $table->foreignId('clinical_status_updated_by')->nullable()->after('clinical_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('clinical_status_updated_at')->nullable()->after('clinical_status_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinical_status_updated_by');
            $table->dropColumn(['clinical_status', 'clinical_status_updated_at']);
        });
    }
};
