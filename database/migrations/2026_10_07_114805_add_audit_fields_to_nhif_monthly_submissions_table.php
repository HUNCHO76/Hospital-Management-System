<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nhif_monthly_submissions', function (Blueprint $table) {
            $table->json('nhif_request_payload')->nullable()->after('submission_remarks');
            $table->text('error_message')->nullable()->after('nhif_response');
            $table->unsignedInteger('retry_count')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nhif_monthly_submissions', function (Blueprint $table) {
            $table->dropColumn(['nhif_request_payload', 'error_message', 'retry_count']);
        });
    }
};
