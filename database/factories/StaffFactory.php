<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * department_id defaults to null rather than an independently-generated
     * Department, since a randomly generated department would not
     * necessarily belong to the randomly generated hospital above — tests
     * that need a department should create one tied to the same hospital_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'hospital_id' => Hospital::factory(),
            'department_id' => null,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'phone' => fake()->unique()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'professional_registration_no' => null,
            'job_title' => 'Receptionist',
            'employment_type' => 'full_time',
            'status' => 'active',
            'hired_at' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
        ];
    }
}
