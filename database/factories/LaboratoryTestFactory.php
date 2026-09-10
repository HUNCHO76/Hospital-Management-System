<?php

namespace Database\Factories;

use App\Models\LaboratoryTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryTest>
 */
class LaboratoryTestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('LAB-????')),
            'name' => fake()->unique()->words(2, true),
            'status' => LaboratoryTest::STATUS_ACTIVE,
        ];
    }
}
