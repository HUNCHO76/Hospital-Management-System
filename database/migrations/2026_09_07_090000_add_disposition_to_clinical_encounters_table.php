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
        Schema::table('clinical_encounters', function (Blueprint $table) {
            // The encounter's clinical outcome (spec §16/42) — distinct from
            // `status`, which is a lifecycle state (open/in_progress/
            // completed/cancelled), not an outcome. Set only at completion
            // time; nullable because open/cancelled encounters never have one.
            $table->string('disposition')->nullable()->after('status');
            $table->text('disposition_notes')->nullable()->after('disposition');

            $table->index('disposition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropIndex(['disposition']);
            $table->dropColumn(['disposition', 'disposition_notes']);
        });
    }
};
