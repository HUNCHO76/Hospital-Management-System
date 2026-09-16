<?php

namespace App\Http\Resources;

use App\Models\ChargeInsuranceAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The insurance responsibility split calculated for one Charge (Phase 7,
 * Step 56) — see the create_charge_insurance_allocations_table migration's
 * own docblock for the full reasoning behind its shape.
 *
 * @mixin ChargeInsuranceAllocation
 */
class ChargeInsuranceAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'charge_id' => $this->charge_id,
            'coverage_type_applied' => $this->coverage_type_applied,
            'coverage_percentage_applied' => $this->coverage_percentage_applied,
            'copay_amount_applied' => $this->copay_amount_applied,
            'eligible_amount' => $this->eligible_amount,
            'covered_amount' => $this->covered_amount,
            'patient_responsibility' => $this->patient_responsibility,
            'currency' => $this->currency,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'insurance_coverage_rule' => $this->whenLoaded('insuranceCoverageRule', fn () => $this->insuranceCoverageRule ? [
                'id' => $this->insuranceCoverageRule->id,
                'coverage_type' => $this->insuranceCoverageRule->coverage_type,
                'requires_preauthorization' => $this->insuranceCoverageRule->requires_preauthorization,
            ] : null),
            'calculated_at' => optional($this->calculated_at)->toIso8601String(),
            'calculated_by' => $this->whenLoaded('calculatedBy', fn () => $this->calculatedBy ? [
                'id' => $this->calculatedBy->id,
                'name' => $this->calculatedBy->UserName,
            ] : null),
        ];
    }
}
