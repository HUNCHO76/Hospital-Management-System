<?php

namespace Database\Factories;

use App\Models\LaboratoryBench;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryBench>
 */
class LaboratoryBenchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'status' => LaboratoryBench::STATUS_ACTIVE,
        ];
    }
}
