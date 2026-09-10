<?php

namespace Database\Factories;

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsuranceScheme>
 */
class InsuranceSchemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insurance_provider_id' => InsuranceProvider::factory(),
            'name' => fake()->words(3, true).' Scheme',
            'code' => strtoupper(fake()->unique()->bothify('SCH-###')),
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
