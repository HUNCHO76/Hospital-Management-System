<?php

namespace App\Http\Resources;

use App\Models\Dispensing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One dispensing event (Phase 6, Step 48) — used both as the prescription's
 * "current" in-progress event and within its dispensing history list.
 *
 * @mixin Dispensing
 */
class DispensingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dispensing_number' => $this->dispensing_number,
            'status' => $this->status,
            'dispensed_by' => $this->whenLoaded('dispensedBy', fn () => $this->dispensedBy ? [
                'id' => $this->dispensedBy->id,
                'name' => $this->dispensedBy->UserName,
            ] : null),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'notes' => $this->notes,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => (new DispensingItemResource($item))->resolve($request))->all(),
            ),
        ];
    }
}
