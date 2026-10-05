<?php

namespace Database\Factories;

use App\Models\NhifDisease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifDisease>
 */
class NhifDiseaseFactory extends Factory
{
    protected $model = NhifDisease::class;

    public function definition(): array
    {
        return [
            'disease_code' => strtoupper($this->faker->bothify('??##')),
            'disease_name' => $this->faker->words(3, true),
            'icd_version_code' => 'ICD10',
            'synced_at' => now(),
            'raw_data' => null,
        ];
    }
}
