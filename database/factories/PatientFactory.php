<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', 'now')->format('Y-m-d'),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'national_id' => fake()->optional()->numerify('##################'),
            'occupation' => fake()->optional()->jobTitle(),
            'marital_status' => fake()->randomElement(['single', 'married', 'divorced', 'widowed']),
            'blood_group' => fake()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'address' => fake()->address(),
            'region' => fake()->state(),
            'district' => fake()->city(),
            'ward' => fake()->streetName(),
            'status' => 'active',
        ];
    }
}
