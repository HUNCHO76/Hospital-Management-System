<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_pre_approval_sessions', function (Blueprint $table) {
            // NHIF response identity fields (top-level response)
            $table->string('nhif_request_id')->nullable()->after('nhif_authorization_no');
            $table->string('nhif_request_no')->nullable()->after('nhif_request_id');

            // Facility code NHIF returned in the response (may differ from what was submitted)
            $table->string('nhif_facility_code_returned')->nullable()->after('facility_code');
        });

        // Extend status enum to include 'accepted' (all items accepted, requiresApproval=false)
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('nhif_pre_approval_sessions', function (Blueprint $table) {
                $table->enum('status', [
                    'draft',
                    'submitted',
                    'partially_approved',
                    'approved',
                    'rejected',
                    'issued',
                    'cancelled',
                    'accepted',
                ])->default('draft')->change();
            });
        } else {
            DB::statement("ALTER TABLE nhif_pre_approval_sessions MODIFY COLUMN status ENUM('draft','submitted','partially_approved','approved','rejected','issued','cancelled','accepted') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        Schema::table('nhif_pre_approval_sessions', function (Blueprint $table) {
            $table->dropColumn(['nhif_request_id', 'nhif_request_no', 'nhif_facility_code_returned']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('nhif_pre_approval_sessions', function (Blueprint $table) {
                $table->enum('status', [
                    'draft', 'submitted', 'partially_approved', 'approved', 'rejected', 'issued', 'cancelled',
                ])->default('draft')->change();
            });
        } else {
            DB::statement("ALTER TABLE nhif_pre_approval_sessions MODIFY COLUMN status ENUM('draft','submitted','partially_approved','approved','rejected','issued','cancelled') NOT NULL DEFAULT 'draft'");
        }
    }
};
