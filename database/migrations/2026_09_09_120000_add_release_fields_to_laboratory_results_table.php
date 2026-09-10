<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Purely additive — nullable columns onto the existing `laboratory_results`
     * table (Steps 33-34), so every existing row is completely unaffected.
     */
    public function up(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->foreignId('released_by')->nullable()->after('return_notes')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable()->after('released_by');
            $table->text('release_comment')->nullable()->after('released_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_by', 'released_at', 'release_comment']);
        });
    }
};
