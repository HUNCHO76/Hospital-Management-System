<?php

namespace Database\Factories;

use App\Models\ClinicalEncounter;
use App\Models\DiagnosisCode;
use App\Models\EncounterDiagnosis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EncounterDiagnosis>
 */
class EncounterDiagnosisFactory extends Factory
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
            'consultation_id' => null,
            'diagnosis_code_id' => DiagnosisCode::factory(),
            'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
            'status' => EncounterDiagnosis::STATUS_PROVISIONAL,
            'diagnosed_by' => User::factory(),
            'diagnosed_at' => now(),
        ];
    }
}
