<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhances maternity profiles with follow-up scheduling (Phase 9 & 10):
     * - follow_up_date (date of planned return visit)
     * - follow_up_purpose (ANC, PNC, Child Growth, Doctor Review)
     * - follow_up_notes (clinical instructions and precautions)
     */
    public function up(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('maternity_profiles', 'follow_up_date')) {
                $table->date('follow_up_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('maternity_profiles', 'follow_up_purpose')) {
                $table->string('follow_up_purpose', 50)->nullable()->after('follow_up_date');
            }
            if (! Schema::hasColumn('maternity_profiles', 'follow_up_notes')) {
                $table->text('follow_up_notes')->nullable()->after('follow_up_purpose');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maternity_profiles', function (Blueprint $table) {
            $columns = [];
            foreach (['follow_up_date', 'follow_up_purpose', 'follow_up_notes'] as $col) {
                if (Schema::hasColumn('maternity_profiles', $col)) {
                    $columns[] = $col;
                }
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};

