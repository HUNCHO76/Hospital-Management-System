<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_plan_items', function (Blueprint $table) {
            // Track who activated and completed each treatment item, and when.
            // Activation = planned → active; completion = active → completed.
            // These are append-only fields; the correction chain (corrected_at/by)
            // handles post-completion amendments, never these timestamps.
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete()->after('created_by');
            $table->timestamp('activated_at')->nullable()->after('activated_by');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete()->after('activated_at');
            $table->timestamp('completed_at')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('care_plan_items', function (Blueprint $table) {
            $table->dropForeign(['activated_by']);
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['activated_by', 'activated_at', 'completed_by', 'completed_at']);
        });
    }
};
