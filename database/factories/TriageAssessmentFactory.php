<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageAssessment>
 */
class TriageAssessmentFactory extends Factory
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
            'assessed_by' => User::factory(),
            'assessment_time' => now(),
            'triage_category' => null,
            'chief_complaint' => fake()->optional()->sentence(),
            'pain_score' => null,
            'status' => TriageAssessment::STATUS_PENDING,
            'notes' => null,
        ];
    }
}
