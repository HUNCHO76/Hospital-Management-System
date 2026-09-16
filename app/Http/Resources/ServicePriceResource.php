<?php

namespace App\Http\Resources;

use App\Models\ServicePrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single ServicePrice (Phase 7, Step 53) — used on a service's own price
 * history and the pricing management list.
 *
 * @mixin ServicePrice
 */
class ServicePriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'effective_from' => optional($this->effective_from)->toDateString(),
            'effective_to' => optional($this->effective_to)->toDateString(),
            'status' => $this->status,
            'is_current' => $this->coversDate(now()),
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
            ] : null),
            'hospital' => $this->whenLoaded('hospital', fn () => $this->hospital ? [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
