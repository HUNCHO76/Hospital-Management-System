<?php

namespace App\Http\Resources;

use App\Models\EncounterDiagnosis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EncounterDiagnosis
 */
class EncounterDiagnosisResource extends JsonResource
{
    /**
     * Transform the diagnosis into its Inertia prop representation. Only
     * fields the frontend needs are exposed — never raw model internals,
     * and never future clinical records (treatment), which are out of
     * scope for this resource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'consultation_id' => $this->consultation_id,
            'diagnosis_code' => $this->whenLoaded('diagnosisCode', fn () => (new DiagnosisCodeResource($this->diagnosisCode))->resolve($request)),
            'diagnosis_type' => $this->diagnosis_type,
            'status' => $this->status,
            'clinical_note' => $this->clinical_note,
            'diagnosed_by' => $this->whenLoaded('diagnosedBy', fn () => $this->diagnosedBy ? [
                'id' => $this->diagnosedBy->id,
                'name' => $this->diagnosedBy->UserName,
            ] : null),
            'diagnosed_at' => optional($this->diagnosed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'corrected_at' => optional($this->corrected_at)->toIso8601String(),
            'correction_reason' => $this->correction_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
