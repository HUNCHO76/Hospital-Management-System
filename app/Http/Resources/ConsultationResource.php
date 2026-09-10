<?php

namespace App\Http\Resources;

use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Consultation
 */
class ConsultationResource extends JsonResource
{
    /**
     * Transform the consultation into its Inertia prop representation. Only
     * fields the frontend needs are exposed — never raw model internals, and
     * never future clinical records (diagnosis, treatment), which are out of
     * scope for this resource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'status' => $this->status,
            'practitioner' => $this->whenLoaded('practitioner', fn () => $this->practitioner ? [
                'id' => $this->practitioner->id,
                'name' => $this->practitioner->relationLoaded('staff') ? $this->practitioner->staff?->full_name : null,
            ] : null),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'chief_complaint' => $this->chief_complaint,
            'history_presenting_illness' => $this->history_presenting_illness,
            'relevant_medical_history' => $this->relevant_medical_history,
            'relevant_surgical_history' => $this->relevant_surgical_history,
            'relevant_social_history' => $this->relevant_social_history,
            'review_of_systems' => $this->review_of_systems,
            'physical_examination' => $this->physical_examination,
            'assessment_summary' => $this->assessment_summary,
            'clinical_notes' => $this->clinical_notes,
            'plan_summary' => $this->plan_summary,
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'corrected_at' => optional($this->corrected_at)->toIso8601String(),
            'correction_reason' => $this->correction_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
