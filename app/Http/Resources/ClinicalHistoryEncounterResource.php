<?php

namespace App\Http\Resources;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One encounter block on the patient's clinical timeline (Step 26) — reuses
 * every existing per-domain resource (TriageAssessmentResource,
 * ConsultationResource, EncounterDiagnosisResource, CarePlanResource,
 * ClinicalNoteResource) rather than re-deriving their field shapes, so this
 * is purely an aggregation/presentation layer over data that already has its
 * own authoritative resource. Complements ClinicalEncounterResource (used by
 * the encounter list/detail pages), which deliberately excludes clinical
 * content — this one exists specifically to include it, gated by the
 * separate clinical-history.view permission.
 *
 * @mixin ClinicalEncounter
 */
class ClinicalHistoryEncounterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'encounter_number' => $this->encounter_number,
            'encounter_type' => $this->encounter_type,
            'status' => $this->status,
            'disposition' => $this->disposition,
            'disposition_notes' => $this->disposition_notes,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'practitioner' => $this->whenLoaded('practitioner', fn () => $this->practitioner ? [
                'id' => $this->practitioner->id,
                'practitioner_number' => $this->practitioner->practitioner_number,
                'name' => $this->practitioner->relationLoaded('staff') ? $this->practitioner->staff?->full_name : null,
            ] : null),
            'triage' => $this->whenLoaded('triageAssessment', fn () => $this->triageAssessment
                ? (new TriageAssessmentResource($this->triageAssessment))->resolve($request)
                : null),
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation
                ? (new ConsultationResource($this->consultation))->resolve($request)
                : null),
            'diagnoses' => $this->whenLoaded('diagnoses', fn () => EncounterDiagnosisResource::collection($this->diagnoses)->resolve($request)),
            'care_plan' => $this->whenLoaded('carePlan', fn () => $this->carePlan
                ? (new CarePlanResource($this->carePlan))->resolve($request)
                : null),
            'clinical_notes' => $this->whenLoaded('clinicalNotes', fn () => ClinicalNoteResource::collection($this->clinicalNotes)->resolve($request)),
            'laboratory_results' => $this->whenLoaded('laboratoryOrders', fn () => $this->releasedLaboratoryResults()),
        ];
    }

    /**
     * Only RELEASED laboratory results are ever presented as final results
     * on a patient's clinical timeline (Phase 4, Step 35, spec Part 23/29)
     * — a draft, pending, or under-review value must never be mistaken for
     * an official one here. No duplicate laboratory-history table was
     * created for this: every record already lives in its own authoritative
     * LaboratoryResult row, reached through the encounter's own
     * laboratoryOrders relation (mirroring how diagnoses/care-plan/clinical
     * notes are aggregated on this same resource).
     *
     * @return list<array<string, mixed>>
     */
    protected function releasedLaboratoryResults(): array
    {
        return $this->laboratoryOrders
            ->flatMap(fn ($order) => $order->items)
            ->pluck('result')
            ->filter(fn ($result) => $result && $result->status === LaboratoryResult::STATUS_RELEASED)
            ->map(fn (LaboratoryResult $result) => [
                'id' => $result->id,
                'status' => $result->status,
                'test_code' => $result->item?->test_code,
                'test_name' => $result->item?->test_name,
                'sample_number' => $result->sample?->sample_number,
                'value' => $result->value_numeric ?? $result->value_text ?? $result->value_code,
                'unit' => $result->unit,
                'abnormal_flag' => $result->abnormal_flag,
                'verified_by' => $result->verifiedBy?->UserName,
                'released_by' => $result->releasedBy?->UserName,
                'released_at' => optional($result->released_at)->toIso8601String(),
                'active_report_id' => $result->item?->laboratoryOrder?->activeReport?->id,
            ])
            ->values()
            ->all();
    }
}
