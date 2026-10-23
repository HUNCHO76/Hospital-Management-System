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
        // 1. Add missing columns to indents table if not already present
        Schema::table('indents', function (Blueprint $table) {
            if (! Schema::hasColumn('indents', 'target_store_id')) {
                $table->foreignId('target_store_id')->nullable()->after('department_id')->constrained('stores')->nullOnDelete();
            }
            if (! Schema::hasColumn('indents', 'indent_date')) {
                $table->date('indent_date')->nullable()->after('requested_by');
            }
            if (! Schema::hasColumn('indents', 'required_by_date')) {
                $table->date('required_by_date')->nullable()->after('indent_date');
            }
            if (! Schema::hasColumn('indents', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });

        // 2. Add missing columns to indent_items table if not already present
        Schema::table('indent_items', function (Blueprint $table) {
            if (! Schema::hasColumn('indent_items', 'estimated_unit_cost')) {
                $table->decimal('estimated_unit_cost', 14, 2)->nullable()->after('quantity_ordered');
            }
            if (! Schema::hasColumn('indent_items', 'estimated_total_cost')) {
                $table->decimal('estimated_total_cost', 14, 2)->nullable()->after('estimated_unit_cost');
            }
            if (! Schema::hasColumn('indent_items', 'purpose')) {
                $table->text('purpose')->nullable()->after('estimated_total_cost');
            }
            if (! Schema::hasColumn('indent_items', 'specification')) {
                $table->text('specification')->nullable()->after('purpose');
            }
        });

        // 3. Backfill indents data if store_id, required_date, justification exist
        if (Schema::hasColumn('indents', 'store_id') && Schema::hasColumn('indents', 'target_store_id')) {
            DB::table('indents')->whereNull('target_store_id')->whereNotNull('store_id')->update([
                'target_store_id' => DB::raw('store_id'),
            ]);
        }
        if (Schema::hasColumn('indents', 'required_date') && Schema::hasColumn('indents', 'required_by_date')) {
            DB::table('indents')->whereNull('required_by_date')->whereNotNull('required_date')->update([
                'required_by_date' => DB::raw('required_date'),
            ]);
        }
        if (Schema::hasColumn('indents', 'indent_date')) {
            DB::table('indents')->whereNull('indent_date')->update([
                'indent_date' => DB::raw('DATE(created_at)'),
            ]);
        }
        if (Schema::hasColumn('indents', 'justification') && Schema::hasColumn('indents', 'notes')) {
            DB::table('indents')->whereNull('notes')->whereNotNull('justification')->update([
                'notes' => DB::raw('justification'),
            ]);
        }

        // 4. Backfill indent_items data if specifications, justification exist
        if (Schema::hasColumn('indent_items', 'specifications') && Schema::hasColumn('indent_items', 'specification')) {
            DB::table('indent_items')->whereNull('specification')->whereNotNull('specifications')->update([
                'specification' => DB::raw('specifications'),
            ]);
        }
        if (Schema::hasColumn('indent_items', 'justification') && Schema::hasColumn('indent_items', 'purpose')) {
            DB::table('indent_items')->whereNull('purpose')->whereNotNull('justification')->update([
                'purpose' => DB::raw('justification'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indent_items', function (Blueprint $table) {
            $cols = [];
            foreach (['specification', 'purpose', 'estimated_total_cost', 'estimated_unit_cost'] as $col) {
                if (Schema::hasColumn('indent_items', $col)) {
                    $cols[] = $col;
                }
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('indents', function (Blueprint $table) {
            if (Schema::hasColumn('indents', 'target_store_id')) {
                try {
                    $table->dropForeign(['target_store_id']);
                } catch (Throwable) {
                    // Ignore if foreign key name differs or sqlite
                }
                $table->dropColumn('target_store_id');
            }

            $cols = [];
            foreach (['notes', 'required_by_date', 'indent_date'] as $col) {
                if (Schema::hasColumn('indents', $col)) {
                    $cols[] = $col;
                }
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
