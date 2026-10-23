<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE indents MODIFY store_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE indents MODIFY required_date DATE NULL');
        } else {
            Schema::table('indents', function (Blueprint $table) {
                if (Schema::hasColumn('indents', 'store_id')) {
                    $table->unsignedBigInteger('store_id')->nullable()->change();
                }
                if (Schema::hasColumn('indents', 'required_date')) {
                    $table->date('required_date')->nullable()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op to prevent data loss or integrity issues on revert
    }
};
