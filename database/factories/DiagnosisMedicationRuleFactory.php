<?php

namespace Database\Factories;

use App\Models\DiagnosisCode;
use App\Models\DiagnosisMedicationRule;
use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagnosisMedicationRule>
 */
class DiagnosisMedicationRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'diagnosis_code_id' => DiagnosisCode::factory(),
            'medication_id' => Medication::factory(),
            'rule_type' => fake()->randomElement(DiagnosisMedicationRule::RULE_TYPES),
            'severity' => fake()->randomElement(DiagnosisMedicationRule::SEVERITIES),
            'description' => fake()->sentence(),
            'source' => null,
            'is_active' => true,
        ];
    }
}
