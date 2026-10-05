<?php

namespace App\Http\Resources;

use App\Models\ClinicalEncounter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalEncounter
 */
class ClinicalEncounterResource extends JsonResource
{
    /**
     * Transform the clinical encounter into its Inertia prop representation.
     * Only fields the frontend needs are exposed — never raw model
     * internals, and never future clinical records (triage, consultation,
     * diagnosis, treatment), which are out of scope for this resource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'encounter_number' => $this->encounter_number,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'middle_name' => $this->patient->middle_name,
                'last_name' => $this->patient->last_name,
                'date_of_birth' => optional($this->patient->date_of_birth)->toDateString(),
                'gender' => $this->patient->gender,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ]),
            'practitioner' => $this->whenLoaded('practitioner', fn () => $this->practitioner ? [
                'id' => $this->practitioner->id,
                'practitioner_number' => $this->practitioner->practitioner_number,
                'practitioner_type' => $this->practitioner->practitioner_type,
                'nhif_practitioner_no' => $this->practitioner->nhif_practitioner_no,
                'name' => $this->practitioner->relationLoaded('staff') ? $this->practitioner->staff?->full_name : null,
            ] : null),
            'reception_visit' => $this->whenLoaded('receptionVisit', fn () => $this->receptionVisit ? [
                'id' => $this->receptionVisit->id,
                'visit_number' => $this->receptionVisit->visit_number,
                'status' => $this->receptionVisit->status,
            ] : null),
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'appointment_number' => $this->appointment->appointment_number,
                'appointment_type' => $this->appointment->appointment_type,
            ] : null),
            'encounter_type' => $this->encounter_type,
            'status' => $this->status,
            'disposition' => $this->disposition,
            'disposition_notes' => $this->disposition_notes,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
