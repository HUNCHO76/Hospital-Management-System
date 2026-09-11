<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\RadiologyOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyOrder>
 */
class RadiologyOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_encounter_id' => ClinicalEncounter::factory(),
            'order_number' => 'RAD-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'priority' => RadiologyOrder::PRIORITY_ROUTINE,
            'clinical_indication' => fake()->sentence(),
            'status' => RadiologyOrder::STATUS_ORDERED,
            'ordered_by' => User::factory(),
            'ordered_at' => now(),
        ];
    }
}
