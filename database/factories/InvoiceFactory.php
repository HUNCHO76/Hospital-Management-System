<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
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
            'patient_account_id' => PatientAccount::factory(),
            'status' => Invoice::STATUS_DRAFT,
            'currency' => config('billing.currency'),
            'created_by' => User::factory(),
        ];
    }
}
