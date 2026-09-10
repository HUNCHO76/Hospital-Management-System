<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTest;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaboratorySample>
 */
class LaboratorySampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_order_id' => LaboratoryOrder::factory(),
            'clinical_encounter_id' => ClinicalEncounter::factory(),
            'patient_id' => Patient::factory(),
            'specimen_type' => fake()->randomElement(LaboratoryTest::SPECIMEN_TYPES),
            'container_type' => fake()->randomElement(LaboratoryTest::SPECIMEN_CONTAINERS),
            'status' => LaboratorySample::STATUS_PENDING_COLLECTION,
        ];
    }
}
