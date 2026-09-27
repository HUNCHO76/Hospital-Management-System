<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_pre_approval_session_items', function (Blueprint $table) {
            // NHIF response identity — stored per service item
            $table->string('nhif_requested_service_id')->nullable()->after('nhif_item_name');
            $table->string('nhif_request_id')->nullable()->after('nhif_requested_service_id');

            // NHIF may return a different item name than the one we sent
            $table->string('nhif_item_name_returned')->nullable()->after('nhif_request_id');

            // Raw NHIF service status and approval flag — these are the authoritative NHIF values
            $table->string('nhif_status')->nullable()->after('nhif_item_name_returned');
            $table->boolean('nhif_requires_approval')->nullable()->after('nhif_status');

            // Service date window from NHIF
            $table->date('nhif_effective_date')->nullable()->after('nhif_requires_approval');
            $table->date('nhif_end_date')->nullable()->after('nhif_effective_date');
            $table->dateTime('nhif_available_after_date')->nullable()->after('nhif_end_date');

            // Coverage and rejection detail
            $table->decimal('nhif_percent_covered', 5, 2)->nullable()->after('nhif_available_after_date');
            $table->text('nhif_rejection_details')->nullable()->after('rejection_reason');
            $table->string('nhif_rejection_reason_code')->nullable()->after('nhif_rejection_details');
        });

        // Extend approval_status enum:
        //   accepted       — Accepted + requiresApproval=false (immediately issuable, no polling needed)
        //   pending_approval — requiresApproval=true (NHIF must approve first)
        //   unknown        — NHIF returned an unrecognised status; block until resolved
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('nhif_pre_approval_session_items', function (Blueprint $table) {
                $table->enum('approval_status', [
                    'pending',
                    'approved',
                    'rejected',
                    'issued',
                    'accepted',
                    'pending_approval',
                    'unknown',
                ])->default('pending')->change();
            });
        } else {
            DB::statement("ALTER TABLE nhif_pre_approval_session_items MODIFY COLUMN approval_status ENUM('pending','approved','rejected','issued','accepted','pending_approval','unknown') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('nhif_pre_approval_session_items', function (Blueprint $table) {
            $table->dropColumn([
                'nhif_requested_service_id',
                'nhif_request_id',
                'nhif_item_name_returned',
                'nhif_status',
                'nhif_requires_approval',
                'nhif_effective_date',
                'nhif_end_date',
                'nhif_available_after_date',
                'nhif_percent_covered',
                'nhif_rejection_details',
                'nhif_rejection_reason_code',
            ]);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('nhif_pre_approval_session_items', function (Blueprint $table) {
                $table->enum('approval_status', [
                    'pending', 'approved', 'rejected', 'issued',
                ])->default('pending')->change();
            });
        } else {
            DB::statement("ALTER TABLE nhif_pre_approval_session_items MODIFY COLUMN approval_status ENUM('pending','approved','rejected','issued') NOT NULL DEFAULT 'pending'");
        }
    }
};
