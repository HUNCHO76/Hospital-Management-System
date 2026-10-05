<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\DischargeDocument;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DischargeDocument>
 */
class DischargeDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'admission_id' => Admission::factory(),
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'version' => 1,
            'status' => DischargeDocument::STATUS_ACTIVE,
            'data_snapshot' => [],
            'generated_by' => User::factory(),
            'generated_at' => now(),
        ];
    }

    public function superseded(): static
    {
        return $this->state(['status' => DischargeDocument::STATUS_SUPERSEDED]);
    }
}
