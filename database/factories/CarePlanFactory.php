<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarePlan>
 */
class CarePlanFactory extends Factory
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
            'consultation_id' => null,
            'status' => CarePlan::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
