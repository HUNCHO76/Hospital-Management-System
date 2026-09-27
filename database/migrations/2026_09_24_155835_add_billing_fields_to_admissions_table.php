<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9, Step 71 — the four fields the "Phase 8, Step 70" schema left out
 * that a real admission workflow needs: `service_id` (so admission billing
 * resolves through the same Service Catalog/ServicePrice stack every other
 * module uses, never a hand-typed rate), `department_id` (the explicit
 * ward/department association), `cancellation_reason` (every other
 * cancellable entity in this app already has one), and `created_by` (who
 * submitted the request — distinct from `admitted_by`, who confirmed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('practitioner_id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('clinical_encounter_id')->constrained()->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('discharge_reason');
            $table->foreignId('created_by')->nullable()->after('admitted_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn('cancellation_reason');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
