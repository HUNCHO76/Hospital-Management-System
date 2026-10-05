<?php

namespace Database\Factories;

use App\Models\NhifPackagePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifPackagePrice>
 */
class NhifPackagePriceFactory extends Factory
{
    protected $model = NhifPackagePrice::class;

    public function definition(): array
    {
        return [
            'item_code' => strtoupper($this->faker->bothify('SVC-####')),
            'price_code' => strtoupper($this->faker->bothify('PRC-####')),
            'item_type_id' => $this->faker->numberBetween(1, 5),
            'item_name' => $this->faker->words(3, true),
            'strength' => null,
            'dosage' => null,
            'package_id' => $this->faker->numberBetween(100, 999),
            'scheme_id' => $this->faker->numberBetween(1000, 9999),
            'unit_price' => $this->faker->randomFloat(2, 100, 5000),
            'is_restricted' => false,
            'is_over_the_counter' => false,
            'maximum_quantity' => null,
            'maximum_quantity_out_patient' => null,
            'maximum_quantity_in_patient' => null,
            'has_co_payment' => false,
            'synced_at' => now(),
            'raw_data' => null,
        ];
    }
}
