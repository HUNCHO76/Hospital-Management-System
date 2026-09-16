<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'hospital_id' => Hospital::factory(),
            'patient_id' => Patient::factory(),
            'amount' => 10000,
        ];
    }
}
