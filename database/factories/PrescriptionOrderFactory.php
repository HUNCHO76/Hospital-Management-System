<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionOrder>
 */
class PrescriptionOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinical_encounter_id' => ClinicalEncounter::factory(),
            'prescription_number' => 'RX-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'status' => PrescriptionOrder::STATUS_ACTIVE,
            'clinical_notes' => fake()->optional()->sentence(),
            'prescriber_id' => User::factory(),
            'prescribed_at' => now(),
        ];
    }
}
