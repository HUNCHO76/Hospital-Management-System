<?php

namespace Database\Factories;

use App\Models\NhifClaim;
use App\Models\NhifFolioDisease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifFolioDisease>
 */
class NhifFolioDiseaseFactory extends Factory
{
    protected $model = NhifFolioDisease::class;

    public function definition(): array
    {
        return [
            'nhif_claim_id' => NhifClaim::factory(),
            'disease_code' => $this->faker->bothify('A##.#'),
            'status' => null,
            'remarks' => null,
            'is_main' => false,
        ];
    }

    public function main(): static
    {
        return $this->state(['is_main' => true]);
    }
}
