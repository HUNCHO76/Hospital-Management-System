<?php

namespace Database\Factories;

use App\Models\DiagnosisCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagnosisCode>
 */
class DiagnosisCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coding_system' => DiagnosisCode::CODING_SYSTEM_ICD10,
            'code' => strtoupper(fake()->unique()->bothify('?##.#')),
            'description' => fake()->sentence(3),
            'category' => fake()->randomElement(['Infectious diseases', 'Respiratory', 'Digestive', 'Circulatory', 'Injury', 'Symptoms and signs']),
            'status' => DiagnosisCode::STATUS_ACTIVE,
        ];
    }
}
