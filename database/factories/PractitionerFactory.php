<?php

namespace Database\Factories;

use App\Models\Practitioner;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Practitioner>
 */
class PractitionerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'practitioner_type' => fake()->randomElement(Practitioner::TYPES),
            'professional_registration_number' => strtoupper(fake()->unique()->bothify('PRN-######')),
            'specialization' => fake()->optional()->jobTitle(),
            'license_number' => strtoupper(fake()->unique()->bothify('LIC-######')),
            'license_expiry_date' => fake()->dateTimeBetween('+6 months', '+3 years')->format('Y-m-d'),
            'status' => 'active',
        ];
    }
}
