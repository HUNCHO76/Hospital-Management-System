<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Practitioner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
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
            'practitioner_id' => Practitioner::factory()->state(['practitioner_type' => 'doctor']),
            'created_by' => User::factory(),
            'status' => Consultation::STATUS_PENDING,
        ];
    }
}
