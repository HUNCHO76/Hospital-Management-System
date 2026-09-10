<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * findConfirmedDuplicate() in PatientService only checks-then-inserts —
     * without a DB-level constraint, two concurrent registrations for the
     * same national ID/passport can both pass that check before either
     * commits. A unique index is the only mechanism that's actually atomic
     * under concurrency; MySQL/SQLite unique indexes treat NULL as distinct
     * from every other NULL, so patients with no national_id/passport never
     * conflict with each other.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Both columns already have a plain (non-unique) index from
            // earlier migrations — replacing rather than duplicating it,
            // since a unique index already serves the same lookup purpose.
            $table->dropIndex(['national_id']);
            $table->dropIndex(['passport_number']);
            $table->unique('national_id');
            $table->unique('passport_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['national_id']);
            $table->dropUnique(['passport_number']);
            $table->index('national_id');
            $table->index('passport_number');
        });
    }
};
