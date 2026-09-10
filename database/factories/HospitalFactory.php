<?php

namespace Database\Factories;

use App\Models\Hospital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hospital>
 */
class HospitalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Hospital',
            'code' => strtoupper(fake()->unique()->bothify('HSP-###')),
            'registration_number' => strtoupper(fake()->unique()->bothify('REG-######')),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'region' => fake()->state(),
            'district' => fake()->city(),
            'ward' => fake()->streetName(),
            'status' => 'active',
        ];
    }
}
