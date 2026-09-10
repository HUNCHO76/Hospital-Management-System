<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('short_name');
            $table->foreignId('category_id')->nullable()->after('description')->constrained('laboratory_test_categories')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('category_id')->constrained('departments')->restrictOnDelete();
            $table->string('specimen_type')->nullable()->after('department_id');
            $table->string('specimen_container')->nullable()->after('specimen_type');
            $table->text('collection_notes')->nullable()->after('specimen_container');
            $table->string('result_type')->nullable()->after('collection_notes');
            $table->string('unit')->nullable()->after('result_type');
            $table->text('reference_range')->nullable()->after('unit');
            $table->unsignedInteger('turnaround_time_minutes')->nullable()->after('reference_range');
            $table->string('default_priority')->default('routine')->after('turnaround_time_minutes');
            $table->date('effective_from')->nullable()->after('default_priority');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignId('created_by')->nullable()->after('effective_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();

            $table->index('department_id');
            $table->index('specimen_type');
            $table->index('result_type');
        });

        // Backfill: the old free-text `category` column carried no
        // foreign-key semantics and nothing else in the app references it
        // directly (LaboratoryOrder only stores laboratory_test_id) — this
        // is pre-production seed/dev data (Step 28's own MedicationSeeder-
        // style "starter set"), so every distinct existing category string
        // is safely promoted to a real laboratory_test_categories row
        // rather than discarded. Raw query-builder calls, not Eloquent, so
        // this migration never depends on model state that may change later.
        $categories = DB::table('laboratory_tests')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        foreach ($categories as $categoryName) {
            $code = Str::of($categoryName)->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();

            $categoryId = DB::table('laboratory_test_categories')->where('code', $code)->value('id');

            if (! $categoryId) {
                $categoryId = DB::table('laboratory_test_categories')->insertGetId([
                    'name' => $categoryName,
                    'code' => $code,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('laboratory_tests')->where('category', $categoryName)->update(['category_id' => $categoryId]);
        }

        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->string('category')->nullable();
        });

        DB::table('laboratory_tests')
            ->join('laboratory_test_categories', 'laboratory_tests.category_id', '=', 'laboratory_test_categories.id')
            ->update(['laboratory_tests.category' => DB::raw('laboratory_test_categories.name')]);

        Schema::table('laboratory_tests', function (Blueprint $table) {
            $table->index('category');
            $table->dropForeign(['category_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'short_name', 'description', 'category_id', 'department_id',
                'specimen_type', 'specimen_container', 'collection_notes',
                'result_type', 'unit', 'reference_range', 'turnaround_time_minutes',
                'default_priority', 'effective_from', 'effective_to', 'created_by', 'updated_by',
            ]);
        });
    }
};
