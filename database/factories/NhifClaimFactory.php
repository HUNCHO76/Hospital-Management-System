<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifClaim>
 */
class NhifClaimFactory extends Factory
{
    protected $model = NhifClaim::class;

    public function definition(): array
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        return [
            'claim_number' => 'CLM-'.strtoupper($this->faker->bothify('####-????')),
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'patient_insurance_id' => PatientInsurance::factory(),
            'clinical_encounter_id' => null,
            'admission_id' => null,
            'claim_type' => NhifClaim::TYPE_OUTPATIENT,
            'status' => NhifClaim::STATUS_DRAFT,
            'folio_no' => $this->faker->numerify('####'),
            'folio_year' => $year,
            'folio_month' => $month,
            'card_no' => $this->faker->bothify('??########'),
            'facility_code' => $this->faker->bothify('FAC###'),
            'attendance_date' => now()->toDateString(),
            'authorization_no' => strtoupper($this->faker->bothify('AUTH-####-????')),
            'primary_diagnosis_code' => $this->faker->bothify('A##.#'),
            'currency' => 'TZS',
            'hms_total' => 50000.00,
            'nhif_allowable_total' => 45000.00,
            'nhif_responsibility_total' => 45000.00,
            'nhif_approved_total' => null,
            'nhif_rejected_total' => null,
            'nhif_adjusted_total' => null,
            'patient_responsibility_total' => 5000.00,
            'nhif_claim_reference' => null,
            'nhif_submission_no' => null,
            'nhif_hash_code' => null,
            'signed_at' => null,
            'signed_by' => null,
            'submitted_at' => null,
            'submitted_by' => null,
            'created_by' => User::factory(),
        ];
    }

    public function ready(): static
    {
        return $this->state(['status' => NhifClaim::STATUS_READY]);
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => NhifClaim::STATUS_SUBMITTED,
            'nhif_claim_reference' => 'SUB-'.strtoupper(uniqid()),
            'nhif_submission_no' => strtoupper(uniqid()),
            'submitted_at' => now(),
        ]);
    }

    public function signed(): static
    {
        return $this->state([
            'status' => NhifClaim::STATUS_READY,
            'signed_at' => now(),
            'signature_method' => 'Manual',
        ]);
    }
}
