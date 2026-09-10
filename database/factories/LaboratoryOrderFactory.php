<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryOrder>
 */
class LaboratoryOrderFactory extends Factory
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
            'order_number' => 'LAB-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'priority' => LaboratoryOrder::PRIORITY_ROUTINE,
            'clinical_indication' => fake()->sentence(),
            'status' => LaboratoryOrder::STATUS_ORDERED,
            'ordered_by' => User::factory(),
            'ordered_at' => now(),
        ];
    }
}
