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
        ];
    }
}
