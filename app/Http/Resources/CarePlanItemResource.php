<?php

namespace App\Http\Resources;

use App\Models\CarePlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CarePlanItem
 */
class CarePlanItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'care_plan_id' => $this->care_plan_id,
            'encounter_diagnosis' => $this->whenLoaded('encounterDiagnosis', fn () => $this->encounterDiagnosis ? [
                'id' => $this->encounterDiagnosis->id,
                'diagnosis_code' => $this->encounterDiagnosis->relationLoaded('diagnosisCode') ? [
                    'code' => $this->encounterDiagnosis->diagnosisCode->code,
                    'description' => $this->encounterDiagnosis->diagnosisCode->description,
                ] : null,
            ] : null),
            'type' => $this->type,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'start_date' => optional($this->start_date)->toDateString(),
            'end_date' => optional($this->end_date)->toDateString(),
            'instructions' => $this->instructions,
            'referred_to_department' => $this->whenLoaded('referredToDepartment', fn () => $this->referredToDepartment ? [
                'id' => $this->referredToDepartment->id,
                'name' => $this->referredToDepartment->name,
            ] : null),
            'referred_to_practitioner' => $this->whenLoaded('referredToPractitioner', fn () => $this->referredToPractitioner ? [
                'id' => $this->referredToPractitioner->id,
                'name' => $this->referredToPractitioner->relationLoaded('staff') ? $this->referredToPractitioner->staff?->full_name : null,
            ] : null),
            'prescription' => $this->whenLoaded('prescription', fn () => $this->prescription ? (new PrescriptionResource($this->prescription))->resolve($request) : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'corrected_at' => optional($this->corrected_at)->toIso8601String(),
            'correction_reason' => $this->correction_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
