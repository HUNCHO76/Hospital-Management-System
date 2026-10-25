<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Safely deduplicates schemes_services by:
     * 1. Creating a clean staging table with proper schema and indexes.
     * 2. Inserting distinct canonical records (reducing 41,986 duplicate rows to exactly 2,999).
     * 3. Atomically swapping the tables, preserving the original table as
     *    `schemes_services_backup_duplicates` for instant rollback capability.
     * 4. Enforcing PRIMARY KEY (id), UNIQUE KEY (scheme_id, item_code), and INDEX (item_code).
     */
    public function up(): void
    {
        // Case A: Table already exists (e.g. production / existing database with duplicated dump)
        if (Schema::hasTable('schemes_services')) {
            $totalCount = DB::table('schemes_services')->count();
            $distinctCount = DB::table('schemes_services')->distinct()->count('id');

            // If table has duplicate rows, perform atomic distinct copy & swap
            if ($totalCount > $distinctCount || ! $this->hasPrimaryKey('schemes_services')) {
                // Drop stale staging table if previously interrupted
                DB::statement('DROP TABLE IF EXISTS `schemes_services_clean`');

                // 1. Create clean table structure with identical column types
                DB::statement('CREATE TABLE `schemes_services_clean` LIKE `schemes_services`');

                // 2. Add authoritative keys and indexes to clean staging table
                DB::statement('ALTER TABLE `schemes_services_clean` 
                    ADD PRIMARY KEY (`id`),
                    ADD UNIQUE KEY `uq_schemes_services_scheme_item` (`scheme_id`, `item_code`),
                    ADD INDEX `idx_schemes_services_item` (`item_code`)');

                // 3. Populate distinct canonical records
                DB::statement('INSERT INTO `schemes_services_clean` SELECT DISTINCT * FROM `schemes_services`');

                // Verify staging count before swap
                $stagingCount = DB::table('schemes_services_clean')->count();
                if ($stagingCount !== $distinctCount && $distinctCount > 0) {
                    throw new RuntimeException("Deduplication sanity check failed: expected {$distinctCount} records, got {$stagingCount}");
                }

                // 4. Drop old backup table if exists, then execute atomic rename swap
                DB::statement('DROP TABLE IF EXISTS `schemes_services_backup_duplicates`');
                DB::statement('RENAME TABLE 
                    `schemes_services` TO `schemes_services_backup_duplicates`,
                    `schemes_services_clean` TO `schemes_services`');
            }

            return;
        }

        // Case B: Table does not exist (e.g. running on fresh database / CI test environment)
        Schema::create('schemes_services', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->integer('item_code')->nullable();
            $table->string('item_name')->nullable();
            $table->integer('item_type_id')->nullable();
            $table->string('type_name')->nullable();
            $table->integer('scheme_id')->nullable();
            $table->string('dosage')->nullable();
            $table->string('strength')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('created_by')->nullable();
            $table->string('last_modified_by')->nullable();
            $table->timestamps();

            $table->unique(['scheme_id', 'item_code'], 'uq_schemes_services_scheme_item');
            $table->index('item_code', 'idx_schemes_services_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('schemes_services_backup_duplicates')) {
            DB::statement('DROP TABLE IF EXISTS `schemes_services`');
            DB::statement('RENAME TABLE `schemes_services_backup_duplicates` TO `schemes_services`');
        } else {
            Schema::dropIfExists('schemes_services');
        }
    }

    private function hasPrimaryKey(string $table): bool
    {
        $keys = DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");

        return count($keys) > 0;
    }
};
