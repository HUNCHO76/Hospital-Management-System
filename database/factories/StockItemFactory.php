<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Medication;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'medication_id' => Medication::factory(),
            'reorder_level' => 10,
            'maximum_level' => null,
            'status' => StockItem::STATUS_ACTIVE,
            'created_by' => User::factory(),
        ];
    }
}
