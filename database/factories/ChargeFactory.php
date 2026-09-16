<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = 1;
        $unitPrice = 20000;

        return [
            'hospital_id' => Hospital::factory(),
            'patient_account_id' => PatientAccount::factory(),
            'patient_id' => Patient::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'gross_amount' => $quantity * $unitPrice,
            'discount_amount' => 0,
            'net_amount' => $quantity * $unitPrice,
            'currency' => config('billing.currency'),
            'status' => Charge::STATUS_PENDING,
            'charged_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
