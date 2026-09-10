<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Models\User;
use App\Models\VitalSign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VitalSign>
 */
class VitalSignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $weight = fake()->randomFloat(2, 40, 100);
        $height = fake()->randomFloat(1, 140, 195);

        return [
            'clinical_encounter_id' => ClinicalEncounter::factory(),
            'triage_assessment_id' => TriageAssessment::factory(),
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'temperature_c' => fake()->randomFloat(1, 36.0, 38.5),
            'systolic_mmhg' => fake()->numberBetween(100, 140),
            'diastolic_mmhg' => fake()->numberBetween(65, 90),
            'pulse_bpm' => fake()->numberBetween(60, 100),
            'respiratory_rate_bpm' => fake()->numberBetween(12, 20),
            'oxygen_saturation_pct' => fake()->numberBetween(95, 100),
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => round((float) $weight / (((float) $height / 100) ** 2), 2),
        ];
    }
}
