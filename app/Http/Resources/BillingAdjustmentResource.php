<?php

namespace App\Http\Resources;

use App\Models\BillingAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillingAdjustment
 */
class BillingAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'type' => $this->type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'adjusted_at' => optional($this->adjusted_at)->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
            ] : null),
            'adjusted_by' => $this->whenLoaded('adjustedBy', fn () => $this->adjustedBy ? [
                'id' => $this->adjustedBy->id,
                'name' => $this->adjustedBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
