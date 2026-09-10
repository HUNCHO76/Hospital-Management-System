<?php

namespace Database\Factories;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryOrderItem>
 */
class LaboratoryOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $test = LaboratoryTest::factory()->create();

        return [
            'laboratory_order_id' => LaboratoryOrder::factory(),
            'laboratory_test_id' => $test->id,
            'test_code' => $test->code,
            'test_name' => $test->name,
            'status' => LaboratoryOrderItem::STATUS_ORDERED,
        ];
    }
}
