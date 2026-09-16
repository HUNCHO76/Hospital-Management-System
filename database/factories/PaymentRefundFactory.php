<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRefund>
 */
class PaymentRefundFactory extends Factory
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
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_account_id' => $account->id,
            'amount' => round((float) $payment->amount / 2, 2),
            'currency' => $payment->currency,
            'refund_method' => 'cash',
            'refund_reference' => null,
            'reason' => $this->faker->sentence(),
            'processed_by' => User::factory(),
            'processed_at' => now(),
            'notes' => null,
        ];
    }
}
