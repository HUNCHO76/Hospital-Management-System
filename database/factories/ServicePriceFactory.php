<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePrice>
 */
class ServicePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'hospital_id' => Hospital::factory(),
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'currency' => config('billing.currency'),
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'status' => ServicePrice::STATUS_ACTIVE,
            'created_by' => User::factory(),
        ];
    }
}
