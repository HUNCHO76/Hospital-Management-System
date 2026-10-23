<?php

namespace App\Http\Resources;

use App\Models\RadiologyOrder;
use App\Services\NHIF\NhifOrderPreApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Used both nested inline on the encounter page and standalone on the
 * Radiology dashboard/worklist/detail pages — mirrors LaboratoryOrderResource
 * exactly. The `patient`/`encounter` fields are only included when the
 * caller has eager-loaded clinicalEncounter.patient.
 *
 * @mixin RadiologyOrder
 */
class RadiologyOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $encounter = $this->relationLoaded('clinicalEncounter') ? $this->clinicalEncounter : null;

        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'order_number' => $this->order_number,
            'priority' => $this->priority,
            'clinical_indication' => $this->clinical_indication,
            'notes' => $this->notes,
            'status' => $this->status,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => (new RadiologyOrderItemResource($item))->resolve($request))->all(),
            ),
            'ordered_by' => $this->whenLoaded('orderedBy', fn () => $this->orderedBy ? [
                'id' => $this->orderedBy->id,
                'name' => $this->orderedBy->UserName,
            ] : null),
            'ordered_at' => optional($this->ordered_at)->toIso8601String(),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->UserName,
            ] : null),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'patient' => $encounter && $encounter->relationLoaded('patient') && $encounter->patient ? [
                'id' => $encounter->patient->id,
                'patient_number' => $encounter->patient->patient_number,
                'first_name' => $encounter->patient->first_name,
                'middle_name' => $encounter->patient->middle_name,
                'last_name' => $encounter->patient->last_name,
                'date_of_birth' => optional($encounter->patient->date_of_birth)->toDateString(),
                'gender' => $encounter->patient->gender,
            ] : null,
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
            ] : null,
            'nhif_pre_approval' => app(NhifOrderPreApprovalService::class)->statusForRadiologyOrder($this->resource),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
