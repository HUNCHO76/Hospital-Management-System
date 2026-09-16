<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5, Step 43 (Integration & Testing) production-readiness fix: the
 * reporting/verification/release worklist queries (RadiologyReportSearchService)
 * filter and sort by `submitted_at`/`verified_at`/`released_at`, but only
 * `status` (already indexed) and the FK columns (auto-indexed by MySQL) had
 * indexes — every practitioner/verifier lookup was already covered via
 * their FK indexes. `returned_at` is added for symmetry with the other
 * three lifecycle timestamps, even though no current query filters on it
 * directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->index('submitted_at');
            $table->index('verified_at');
            $table->index('returned_at');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('radiology_reports', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['verified_at']);
            $table->dropIndex(['returned_at']);
            $table->dropIndex(['released_at']);
        });
    }
};
