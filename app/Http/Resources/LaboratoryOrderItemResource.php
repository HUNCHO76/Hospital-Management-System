<?php

namespace App\Http\Resources;

use App\Models\LaboratoryOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryOrderItem
 */
class LaboratoryOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_order_id' => $this->laboratory_order_id,
            'laboratory_test_id' => $this->laboratory_test_id,
            'test_code' => $this->test_code,
            'test_name' => $this->test_name,
            'clinical_note' => $this->clinical_note,
            'specimen_source' => $this->specimen_source,
            'status' => $this->status,
            // The authoritative up-to-date progress once a result exists —
            // item.status itself never advances past "result_pending" (see
            // LaboratoryOrderController::eagerLoads()).
            'result' => $this->whenLoaded('result', fn () => $this->result ? [
                'id' => $this->result->id,
                'status' => $this->result->status,
            ] : null),
            // Read live from the catalog (Phase 4, Step 31) rather than
            // snapshotted — collection decisions should follow the
            // hospital's current specimen/container requirement, not
            // whatever it was when the test was ordered.
            'specimen_type' => $this->whenLoaded('laboratoryTest', fn () => $this->laboratoryTest?->specimen_type),
            'specimen_container' => $this->whenLoaded('laboratoryTest', fn () => $this->laboratoryTest?->specimen_container),
            'samples' => $this->whenLoaded(
                'samples',
                fn () => $this->samples->map(fn ($sample) => [
                    'id' => $sample->id,
                    'sample_number' => $sample->sample_number,
                    'status' => $sample->status,
                ])->all(),
            ),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
