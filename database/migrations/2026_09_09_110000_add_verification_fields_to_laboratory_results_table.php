<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Purely additive — nullable columns onto the existing Step 33
     * `laboratory_results` table, so every existing row (still sitting at
     * whatever pre-verification status it was in) is completely unaffected.
     * No existing column is renamed, retyped, or dropped.
     */
    public function up(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('amended_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->foreignId('verified_by')->nullable()->after('reviewed_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('verification_comment')->nullable()->after('verified_at');

            $table->foreignId('authorized_by')->nullable()->after('verification_comment')->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at')->nullable()->after('authorized_by');

            $table->foreignId('returned_by')->nullable()->after('authorized_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->string('return_reason')->nullable()->after('returned_at');
            $table->text('return_notes')->nullable()->after('return_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_results', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['authorized_by']);
            $table->dropForeign(['returned_by']);
            $table->dropColumn([
                'reviewed_by', 'reviewed_at',
                'verified_by', 'verified_at', 'verification_comment',
                'authorized_by', 'authorized_at',
                'returned_by', 'returned_at', 'return_reason', 'return_notes',
            ]);
        });
    }
};
