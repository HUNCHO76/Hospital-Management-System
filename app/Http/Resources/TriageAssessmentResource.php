<?php

namespace App\Http\Resources;

use App\Models\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TriageAssessment
 */
class TriageAssessmentResource extends JsonResource
{
    /**
     * Transform the triage assessment into its Inertia prop representation.
     * Only fields the frontend needs are exposed — never raw model
     * internals, and never future clinical records (consultation,
     * diagnosis, treatment), which are out of scope for this resource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'status' => $this->status,
            'triage_category' => $this->triage_category,
            'assessed_by' => $this->whenLoaded('assessedBy', fn () => $this->assessedBy ? [
                'id' => $this->assessedBy->id,
                'name' => $this->assessedBy->UserName,
            ] : null),
            'assessment_time' => optional($this->assessment_time)->toIso8601String(),
            'chief_complaint' => $this->chief_complaint,
            'pain_score' => $this->pain_score,
            'notes' => $this->notes,
            // Resolved to a plain array of arrays rather than
            // VitalSignResource::collection(...) left as-is: a resource
            // collection embedded as a value (not returned at the top
            // level) still applies Laravel's default {"data": [...]} envelope
            // when it's later JSON-encoded, which would double-wrap it here.
            'vital_signs' => $this->whenLoaded(
                'vitalSigns',
                fn () => $this->vitalSigns->map(fn ($vitalSign) => (new VitalSignResource($vitalSign))->resolve($request))->all(),
            ),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
