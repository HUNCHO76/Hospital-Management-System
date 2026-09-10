<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientContact>
 */
class PatientContactFactory extends Factory
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
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['spouse', 'parent', 'sibling', 'child', 'friend']),
            'phone' => fake()->phoneNumber(),
            'alternative_phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->address(),
            'is_primary' => false,
        ];
    }
}
