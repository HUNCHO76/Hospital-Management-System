<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionTransfer>
 */
class AdmissionTransferFactory extends Factory
{
    protected $model = AdmissionTransfer::class;

    public function definition(): array
    {
        return [
            'admission_id' => Admission::factory()->active(),
            'from_hospital_id' => Hospital::factory(),
            'to_hospital_id' => Hospital::factory(),
            'from_ward_type' => $this->faker->randomElement(['general', 'icu', 'maternity']),
            'to_ward_type' => $this->faker->randomElement(['general', 'icu', 'maternity']),
            'from_room_type' => null,
            'to_room_type' => null,
            'reason' => $this->faker->sentence(),
            'charges_per_day' => null,
            'practitioner_no' => null,
            'practitioners_remarks' => null,
            'status' => AdmissionTransfer::STATUS_PENDING,
            'nhif_admission_no' => null,
            'nhif_status' => AdmissionTransfer::NHIF_STATUS_NOT_REQUIRED,
            'nhif_transfer_no' => null,
            'nhif_ward_type_id' => null,
            'nhif_room_type_id' => null,
            'http_status' => null,
            'raw_request' => null,
            'raw_response' => null,
            'error_message' => null,
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'completed_at' => null,
            'completed_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => AdmissionTransfer::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => User::factory(),
        ]);
    }

    public function nhifSubmitted(): static
    {
        return $this->state([
            'nhif_status' => AdmissionTransfer::NHIF_STATUS_SUBMITTED,
            'nhif_transfer_no' => 'TRF-'.$this->faker->numerify('#######'),
        ]);
    }
}
