<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeInsuranceAllocation>
 */
class ChargeInsuranceAllocationFactory extends Factory
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
            'invoice_id' => Invoice::factory(),
            'charge_id' => Charge::factory(),
            'patient_insurance_id' => PatientInsurance::factory(),
            'insurance_coverage_rule_id' => null,
            'coverage_type_applied' => null,
            'coverage_percentage_applied' => null,
            'copay_amount_applied' => null,
            'eligible_amount' => 10000,
            'covered_amount' => 0,
            'patient_responsibility' => 10000,
            'currency' => config('billing.currency'),
            'status' => ChargeInsuranceAllocation::STATUS_NOT_COVERED,
            'rejection_reason' => null,
            'calculated_at' => now(),
            'calculated_by' => User::factory(),
        ];
    }
}
