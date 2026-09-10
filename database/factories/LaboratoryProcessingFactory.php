<?php

namespace Database\Factories;

use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryProcessing>
 */
class LaboratoryProcessingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_sample_id' => LaboratorySample::factory(),
            'laboratory_order_item_id' => LaboratoryOrderItem::factory(),
            'status' => LaboratoryProcessing::STATUS_RECEIVED,
        ];
    }
}
