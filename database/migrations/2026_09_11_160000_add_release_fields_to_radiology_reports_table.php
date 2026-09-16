<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology result release (Phase 5, Step 44) — release metadata lives
 * directly on the same `radiology_reports` row, mirroring
 * `laboratory_results`'s own `released_by`/`released_at`/`release_comment`
 * columns exactly (Step 35's precedent: "Release itself lives directly on
 * this row... mirroring verified_by/..."). No separate release/history
 * table was created — the existing AuditLogService trail (extended here
 * with `radiology_result_released`) already answers who released it and
 * when, the same reasoning the Step 43 verification migration already
 * applied to this same table.
 *
 * Radiology has no AUTHORIZED status between VERIFIED and RELEASED (Part 3:
 * "If the existing architecture has AUTHORIZED... reuse it. Do not invent
 * unnecessary workflow states." — Radiology's own Step 43 architecture
 * never introduced one, unlike Laboratory's, so none is introduced here
 * either) — the minimum valid transition VERIFIED -> RELEASED is what is
 * implemented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->foreignId('released_by')->nullable()->after('return_comments')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable()->after('released_by');
            $table->text('release_notes')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn(['released_at', 'release_notes']);
        });
    }
};
