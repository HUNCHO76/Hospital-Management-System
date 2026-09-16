<?php

namespace Database\Factories;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\PrescriptionOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispensingItem>
 */
class DispensingItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dispensing_id' => Dispensing::factory(),
            'prescription_order_item_id' => PrescriptionOrderItem::factory(),
            'dispensed_quantity' => 1,
            'status' => DispensingItem::STATUS_DISPENSED,
        ];
    }
}
