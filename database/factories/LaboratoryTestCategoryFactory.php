<?php

namespace Database\Factories;

use App\Models\LaboratoryTestCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryTestCategory>
 */
class LaboratoryTestCategoryFactory extends Factory
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
            'status' => LaboratoryTestCategory::STATUS_ACTIVE,
        ];
    }
}
