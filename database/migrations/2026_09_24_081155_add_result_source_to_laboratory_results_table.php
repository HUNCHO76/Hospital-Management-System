<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether a result was entered manually or accompanied by an
     * uploaded machine/analyzer document (Phase 4 Machine Document Upload).
     * Nullable — set to 'machine_document' when a document is first uploaded;
     * treated as manual entry when null.
     */
    public function up(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->string('result_source')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->dropColumn('result_source');
        });
    }
};
