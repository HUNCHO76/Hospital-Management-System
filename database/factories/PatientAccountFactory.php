<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAccount>
 */
class PatientAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'status' => PatientAccount::STATUS_ACTIVE,
            'currency' => config('billing.currency'),
            'created_by' => User::factory(),
        ];
    }
}
