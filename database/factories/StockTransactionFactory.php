<?php

namespace Database\Factories;

use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_number' => 'STK-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'stock_item_id' => StockItem::factory(),
            'stock_batch_id' => StockBatch::factory(),
            'transaction_type' => StockTransaction::TYPE_RECEIPT,
            'quantity' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'performed_by' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}
