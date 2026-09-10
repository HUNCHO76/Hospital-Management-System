<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarePlanItem>
 */
class CarePlanItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'care_plan_id' => CarePlan::factory(),
            'type' => CarePlanItem::TYPE_CLINICAL_INTERVENTION,
            'description' => fake()->sentence(4),
            'status' => CarePlanItem::STATUS_PLANNED,
            'created_by' => User::factory(),
        ];
    }
}
