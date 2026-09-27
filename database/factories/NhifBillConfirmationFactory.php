<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\NhifBillConfirmation;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NhifBillConfirmation>
 */
class NhifBillConfirmationFactory extends Factory
{
    protected $model = NhifBillConfirmation::class;

    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'patient_insurance_id' => PatientInsurance::factory(),
            'nhif_authorization_no' => strtoupper($this->faker->bothify('AUTH-####-????')),
            'card_no' => $this->faker->bothify('??########'),
            'facility_code' => $this->faker->bothify('FAC###'),
            'attendance_date' => now()->toDateString(),
            'total_amount' => $this->faker->numberBetween(5000, 500000),
            'confirmation_code' => null,
            'confirmation_status' => NhifBillConfirmation::STATUS_PENDING,
            'nhif_response' => null,
            'confirmed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state([
            'confirmation_code' => $this->faker->numerify('######'),
            'confirmation_status' => NhifBillConfirmation::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['confirmation_status' => NhifBillConfirmation::STATUS_FAILED]);
    }
}
