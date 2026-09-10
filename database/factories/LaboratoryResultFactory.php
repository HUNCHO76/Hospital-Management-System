<?php

namespace Database\Factories;

use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryResult>
 */
class LaboratoryResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_order_item_id' => LaboratoryOrderItem::factory(),
            'laboratory_sample_id' => LaboratorySample::factory(),
            'laboratory_test_id' => LaboratoryTest::factory(),
            'status' => LaboratoryResult::STATUS_RESULT_PENDING,
            'result_type' => LaboratoryTest::RESULT_TYPES[0],
        ];
    }
}
