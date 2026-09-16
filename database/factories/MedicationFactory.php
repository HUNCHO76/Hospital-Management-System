<?php

namespace Database\Factories;

use App\Models\Medication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medication>
 */
class MedicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('MED-????')),
            'generic_name' => fake()->unique()->words(2, true),
            'brand_name' => null,
            'strength' => fake()->randomElement(['250 mg', '500 mg', '5 mg', '10 mg', '20 mg']),
            'form' => fake()->randomElement(Medication::FORMS),
            'route' => fake()->randomElement(Medication::ROUTES),
            'category' => fake()->randomElement(['Analgesic', 'Antibiotic', 'Antimalarial', 'Antihypertensive', 'Antidiabetic']),
            'status' => Medication::STATUS_ACTIVE,
        ];
    }
}
