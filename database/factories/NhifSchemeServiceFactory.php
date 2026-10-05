<?php

namespace Database\Factories;

use App\Models\NhifSchemeService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifSchemeService>
 */
class NhifSchemeServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => $this->faker->numberBetween(1000, 9999),
            'scheme_name' => $this->faker->words(2, true),
            'item_code' => strtoupper($this->faker->bothify('SVC-####')),
            'item_name' => $this->faker->words(3, true),
            'item_type_id' => $this->faker->numberBetween(1, 5),
            'type_name' => null,
            'unit_price' => $this->faker->randomFloat(2, 100, 5000),
            'is_active' => true,
            'start_date' => null,
            'end_date' => null,
            'synced_at' => now(),
            'raw_data' => null,
        ];
    }
}
