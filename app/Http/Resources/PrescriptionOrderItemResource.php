<?php

namespace App\Http\Resources;

use App\Models\PrescriptionOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PrescriptionOrderItem
 */
class PrescriptionOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescription_order_id' => $this->prescription_order_id,
            'medication_id' => $this->medication_id,
            // Snapshotted at prescribing time (Phase 6, Step 46) — a later
            // catalog edit to the medication's name/strength/form/route must
            // never change what an existing prescription is displayed as
            // having actually prescribed.
            'medication_code_snapshot' => $this->medication_code_snapshot,
            'medication_name_snapshot' => $this->medication_name_snapshot,
            'strength_snapshot' => $this->strength_snapshot,
            'form_snapshot' => $this->form_snapshot,
            'route_snapshot' => $this->route_snapshot,
            'dose' => $this->dose,
            'dose_unit' => $this->dose_unit,
            'frequency' => $this->frequency,
            'route' => $this->route,
            'duration' => $this->duration,
            'duration_unit' => $this->duration_unit,
            'quantity' => $this->quantity,
            'quantity_unit' => $this->quantity_unit,
            'instructions' => $this->instructions,
            'status' => $this->status,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
