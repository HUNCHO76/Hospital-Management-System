<?php

namespace App\Http\Resources;

use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Charge (Phase 7, Step 52, extended in Step 54 for automated
 * capture and Step 55 for invoice linkage) — used on the account's
 * financial timeline (spec §37) and as an invoice's own line items.
 *
 * @mixin Charge
 */
class ChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'charge_number' => $this->charge_number,
            'invoice_id' => $this->invoice_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
            ] : null),
            'service_code_snapshot' => $this->service_code_snapshot,
            'description' => $this->description,
            'notes' => $this->notes,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->gross_amount,
            'discount_amount' => $this->discount_amount,
            'net_amount' => $this->net_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'encounter' => $this->whenLoaded('encounter', fn () => $this->encounter ? [
                'id' => $this->encounter->id,
                'encounter_number' => $this->encounter->encounter_number,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
            'charged_at' => optional($this->charged_at)->toIso8601String(),
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'insurance_allocation' => $this->whenLoaded('insuranceAllocation', fn () => $this->insuranceAllocation
                ? (new ChargeInsuranceAllocationResource($this->insuranceAllocation))->resolve($request)
                : null),
        ];
    }
}
