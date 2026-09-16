<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-'.now()->year.'-'.strtoupper(fake()->unique()->bothify('######')),
            'receipt_number' => 'RCT-'.now()->year.'-'.strtoupper(fake()->unique()->bothify('######')),
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'patient_account_id' => PatientAccount::factory(),
            'invoice_id' => Invoice::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => 10000,
            'currency' => config('billing.currency'),
            'payment_method' => Payment::METHOD_CASH,
            'reference_number' => null,
            'status' => Payment::STATUS_POSTED,
            'notes' => null,
            'received_by' => User::factory(),
            'posted_at' => now(),
        ];
    }
}
