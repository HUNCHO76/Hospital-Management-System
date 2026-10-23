<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cancel any existing laboratory orders where all items are cancelled
        DB::table('laboratory_orders')
            ->where('status', '!=', 'cancelled')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('laboratory_order_items')
                    ->whereColumn('laboratory_order_items.laboratory_order_id', 'laboratory_orders.id')
                    ->where('laboratory_order_items.status', '!=', 'cancelled');
            })
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('laboratory_order_items')
                    ->whereColumn('laboratory_order_items.laboratory_order_id', 'laboratory_orders.id');
            })
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'All order items were cancelled',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time data cleanup migration, irreversible.
    }
};
