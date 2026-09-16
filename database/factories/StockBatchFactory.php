<?php

namespace Database\Factories;

use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBatch>
 */
class StockBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_item_id' => StockItem::factory(),
            'batch_number' => strtoupper(fake()->unique()->bothify('BATCH-####')),
            'manufacture_date' => now()->subMonths(3)->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'quantity' => 100,
            'unit_cost' => fake()->randomFloat(2, 1, 500),
            'supplier_name' => fake()->company(),
            'status' => StockBatch::STATUS_ACTIVE,
            'created_by' => User::factory(),
        ];
    }
}
