<?php

namespace App\Http\Resources;

use App\Models\RadiologyOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RadiologyOrderItem
 */
class RadiologyOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'radiology_order_id' => $this->radiology_order_id,
            'radiology_procedure_id' => $this->radiology_procedure_id,
            'procedure_code' => $this->procedure_code,
            'procedure_name' => $this->procedure_name,
            // Snapshotted at order time (Phase 5, Step 38, Part 5) — a
            // later catalog edit to the procedure's modality/body sites
            // must never change what an existing order is displayed as
            // having actually ordered.
            'modality_snapshot' => $this->modality_snapshot,
            'body_site_snapshot' => $this->body_site_snapshot,
            'clinical_note' => $this->clinical_note,
            'status' => $this->status,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
            // The item's own ordered/cancelled status says nothing about
            // where it actually is in the scheduling/reception/examination
            // pipeline — that detail lives entirely on its appointment, so
            // it is surfaced here rather than left only on a separate
            // scheduling screen (only included when eager-loaded, e.g. on
            // the order detail page).
            'appointment' => $this->whenLoaded('activeRadiologyAppointment', fn () => $this->activeRadiologyAppointment ? [
                'id' => $this->activeRadiologyAppointment->id,
                'status' => $this->activeRadiologyAppointment->status,
                'resource_name' => $this->activeRadiologyAppointment->resource_name,
                'scheduled_start' => optional($this->activeRadiologyAppointment->scheduled_start)->toIso8601String(),
            ] : null),
        ];
    }
}
