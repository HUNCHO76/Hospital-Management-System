<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentReversal>
 */
class PaymentReversalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hospital = Hospital::factory()->create();
        $patient = Patient::factory()->create();
        $account = PatientAccount::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id]);
        $invoice = Invoice::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'patient_account_id' => $account->id]);
        $payment = Payment::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'patient_account_id' => $account->id, 'invoice_id' => $invoice->id]);

        return [
            'original_payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_account_id' => $account->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reason' => $this->faker->sentence(),
            'reversed_by' => User::factory(),
            'reversed_at' => now(),
            'notes' => null,
        ];
    }
}
