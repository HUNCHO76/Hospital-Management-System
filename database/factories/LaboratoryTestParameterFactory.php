<?php

namespace Database\Factories;

use App\Models\LaboratoryTestParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryTestParameter>
 */
class LaboratoryTestParameterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('PARAM-????'));

        return [
            'code' => $code,
            'name' => fake()->words(2, true),
            'unit' => fake()->randomElement(['g/dL', 'mmol/L', 'U/L', '10^9/L', '%']),
            'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
            'decimal_places' => 1,
            'display_order' => 0,
            'is_calculated' => false,
            'active' => true,
        ];
    }

    public function option(): static
    {
        return $this->state([
            'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
            'options_json' => ['positive', 'negative'],
            'unit' => null,
        ]);
    }

    public function text(): static
    {
        return $this->state([
            'data_type' => LaboratoryTestParameter::DATA_TYPE_TEXT,
            'unit' => null,
        ]);
    }
}
