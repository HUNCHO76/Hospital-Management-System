<?php

namespace Database\Factories;

use App\Models\BillingAdjustment;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAdjustment>
 */
class BillingAdjustmentFactory extends Factory
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

        return [
            'invoice_id' => $invoice->id,
            'hospital_id' => $hospital->id,
            'patient_id' => $patient->id,
            'patient_account_id' => $account->id,
            'type' => BillingAdjustment::TYPE_CREDIT_ADJUSTMENT,
            'direction' => BillingAdjustment::DIRECTION_CREDIT,
            'amount' => 5000.00,
            'currency' => config('billing.currency', 'TZS'),
            'reason' => $this->faker->sentence(),
            'adjusted_by' => User::factory(),
            'adjusted_at' => now(),
            'notes' => null,
        ];
    }
}
