<?php

namespace Database\Factories;

use App\Models\LaboratoryReferenceRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratoryReferenceRange>
 */
class LaboratoryReferenceRangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $low = fake()->randomFloat(1, 1.0, 50.0);
        $high = $low + fake()->randomFloat(1, 5.0, 100.0);

        return [
            'sex' => LaboratoryReferenceRange::SEX_ANY,
            'age_min_days' => null,
            'age_max_days' => null,
            'low' => $low,
            'high' => $high,
            'critical_low' => $low - fake()->randomFloat(1, 1.0, 10.0),
            'critical_high' => $high + fake()->randomFloat(1, 5.0, 20.0),
            'notes' => null,
        ];
    }

    public function male(): static
    {
        return $this->state(['sex' => LaboratoryReferenceRange::SEX_MALE]);
    }

    public function female(): static
    {
        return $this->state(['sex' => LaboratoryReferenceRange::SEX_FEMALE]);
    }

    public function adultOnly(): static
    {
        return $this->state([
            'age_min_days' => 6570,
            'age_max_days' => null,
        ]);
    }
}
