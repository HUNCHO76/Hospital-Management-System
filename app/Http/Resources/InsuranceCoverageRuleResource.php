<?php

namespace App\Http\Resources;

use App\Models\InsuranceCoverageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single InsuranceCoverageRule (Phase 7, Step 56) — used for the coverage
 * rule admin list.
 *
 * @mixin InsuranceCoverageRule
 */
class InsuranceCoverageRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'insurance_scheme' => $this->whenLoaded('insuranceScheme', fn () => $this->insuranceScheme ? [
                'id' => $this->insuranceScheme->id,
                'name' => $this->insuranceScheme->name,
                'code' => $this->insuranceScheme->code,
            ] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
            ] : null),
            'category' => $this->category,
            'coverage_type' => $this->coverage_type,
            'coverage_percentage' => $this->coverage_percentage,
            'fixed_copay_amount' => $this->fixed_copay_amount,
            'requires_preauthorization' => $this->requires_preauthorization,
            'status' => $this->status,
            'effective_from' => optional($this->effective_from)->toDateString(),
            'effective_to' => optional($this->effective_to)->toDateString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
