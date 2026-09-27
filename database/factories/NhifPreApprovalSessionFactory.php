<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\NhifPreApprovalSession;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NhifPreApprovalSession>
 */
class NhifPreApprovalSessionFactory extends Factory
{
    protected $model = NhifPreApprovalSession::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'patient_insurance_id' => PatientInsurance::factory(),
            'hospital_id' => Hospital::factory(),
            'clinical_encounter_id' => null,
            'nhif_authorization_no' => strtoupper($this->faker->bothify('AUTH-####-????')),
            'nhif_request_id' => null,
            'nhif_request_no' => null,
            'nhif_card_no' => $this->faker->bothify('??########'),
            'facility_code' => $this->faker->bothify('FAC###'),
            'nhif_facility_code_returned' => null,
            'practitioner_no' => null,
            'status' => NhifPreApprovalSession::STATUS_DRAFT,
            'submitted_at' => null,
            'synced_at' => null,
            'issued_at' => null,
            'request_payload' => null,
            'response_payload' => null,
            'issue_payload' => null,
            'issue_response_payload' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => NhifPreApprovalSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'nhif_request_id' => (string) Str::uuid(),
            'nhif_request_no' => (string) $this->faker->numerify('##########'),
        ]);
    }

    /** All items accepted directly (requiresApproval=false) — immediately issuable. */
    public function accepted(): static
    {
        return $this->state([
            'status' => NhifPreApprovalSession::STATUS_ACCEPTED,
            'submitted_at' => now(),
            'nhif_request_id' => (string) Str::uuid(),
            'nhif_request_no' => (string) $this->faker->numerify('##########'),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => NhifPreApprovalSession::STATUS_APPROVED,
            'submitted_at' => now(),
            'synced_at' => now(),
            'nhif_request_id' => (string) Str::uuid(),
            'nhif_request_no' => (string) $this->faker->numerify('##########'),
        ]);
    }

    public function partiallyApproved(): static
    {
        return $this->state([
            'status' => NhifPreApprovalSession::STATUS_PARTIALLY_APPROVED,
            'submitted_at' => now(),
            'synced_at' => now(),
            'nhif_request_id' => (string) Str::uuid(),
            'nhif_request_no' => (string) $this->faker->numerify('##########'),
        ]);
    }

    public function issued(): static
    {
        return $this->state([
            'status' => NhifPreApprovalSession::STATUS_ISSUED,
            'submitted_at' => now(),
            'synced_at' => now(),
            'issued_at' => now(),
            'nhif_request_id' => (string) Str::uuid(),
            'nhif_request_no' => (string) $this->faker->numerify('##########'),
        ]);
    }
}
