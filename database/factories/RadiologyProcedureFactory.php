<?php

namespace Database\Factories;

use App\Models\RadiologyProcedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyProcedure>
 */
class RadiologyProcedureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('RAD-????')),
            'name' => fake()->unique()->words(2, true),
            'status' => RadiologyProcedure::STATUS_ACTIVE,
        ];
    }
}
