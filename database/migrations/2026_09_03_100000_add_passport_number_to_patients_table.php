<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add secondary identification fields. `passport_number` participates in
     * confirmed-duplicate detection alongside `national_id`; both are indexed
     * for that lookup. `other_identification` is free-text and not indexed.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('passport_number')->nullable()->after('national_id');
            $table->string('other_identification')->nullable()->after('passport_number');
            $table->index('passport_number');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['passport_number']);
            $table->dropColumn(['passport_number', 'other_identification']);
        });
    }
};
