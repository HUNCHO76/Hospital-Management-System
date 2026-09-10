<?php

namespace Database\Factories;

use App\Models\InsuranceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsuranceProvider>
 */
class InsuranceProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Insurance',
            'code' => strtoupper(fake()->unique()->bothify('INS-###')),
            'registration_number' => strtoupper(fake()->unique()->bothify('REG-######')),
            'type' => fake()->randomElement(['government', 'private', 'corporate', 'other']),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'api_enabled' => false,
            'status' => 'active',
        ];
    }
}
