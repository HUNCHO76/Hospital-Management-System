<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SVC-????')),
            'name' => fake()->unique()->words(3, true),
            'category' => fake()->randomElement(Service::CATEGORIES),
            'is_billable' => true,
            'status' => Service::STATUS_ACTIVE,
            'created_by' => User::factory(),
        ];
    }
}
