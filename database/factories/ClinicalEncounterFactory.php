<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalEncounter>
 */
class ClinicalEncounterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'reception_visit_id' => null,
            'appointment_id' => null,
            'department_id' => Department::factory(),
            'practitioner_id' => null,
            'encounter_type' => fake()->randomElement(ClinicalEncounter::TYPES),
            'status' => ClinicalEncounter::STATUS_OPEN,
            'started_at' => null,
            'ended_at' => null,
            'created_by' => User::factory(),
            'closed_by' => null,
        ];
    }
}
