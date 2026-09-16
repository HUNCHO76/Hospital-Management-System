<?php

namespace App\Http\Resources;

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryResult;
use App\Models\PrescriptionOrder;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Services\DispensingService;
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
    public function __construct(ClinicalEncounter $resource, protected DispensingService $dispensingService)
    {
        parent::__construct($resource);
    }

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
            'radiology_results' => $this->whenLoaded('radiologyOrders', fn () => $this->releasedRadiologyResults($request)),
            'radiology_orders' => $this->whenLoaded('radiologyOrders', fn () => $this->radiologyOrders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'priority' => $order->priority,
                'status' => $order->status,
                'ordered_at' => optional($order->ordered_at)->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'procedure_code' => $item->procedure_code,
                    'procedure_name' => $item->procedure_name,
                    'modality_snapshot' => $item->modality_snapshot,
                    'body_site_snapshot' => $item->body_site_snapshot,
                    'status' => $item->status,
                ])->values()->all(),
            ])->values()->all()),
            // Prescription is its own sibling branch off the encounter
            // (Phase 6, Step 46) — distinct from the pre-existing,
            // unrelated CarePlanItem-nested Prescription already surfaced
            // via `care_plan.items[].prescription` above.
            'prescription_orders' => $this->whenLoaded('prescriptionOrders', fn () => $this->prescriptionOrders->map(fn ($order) => [
                'id' => $order->id,
                'prescription_number' => $order->prescription_number,
                'status' => $order->status,
                'prescribed_at' => optional($order->prescribed_at)->toIso8601String(),
                'prescriber' => $order->relationLoaded('prescriber') && $order->prescriber ? [
                    'id' => $order->prescriber->id,
                    'name' => $order->prescriber->UserName,
                ] : null,
                // Verification/dispensing progress (Phase 6, Step 51, spec
                // §19) — the order's own `status` only tracks
                // draft/active/cancelled, never fulfillment, so it alone
                // cannot answer "was this actually supplied". Reuses
                // PrescriptionVerification and
                // DispensingService::fulfillmentStatus() — the same
                // authoritative sources the dispensing worklist and Step 50
                // prescription report use — rather than re-deriving either,
                // bounded to this encounter's own orders (never the whole
                // table).
                'verification_status' => $order->relationLoaded('verification') ? $order->verification?->status : null,
                'dispensing_status' => $order->status === PrescriptionOrder::STATUS_CANCELLED
                    ? null
                    : $this->dispensingService->fulfillmentStatus($order),
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'medication_name_snapshot' => $item->medication_name_snapshot,
                    'strength_snapshot' => $item->strength_snapshot,
                    'dose' => $item->dose,
                    'dose_unit' => $item->dose_unit,
                    'frequency' => $item->frequency,
                    'route' => $item->route,
                    'duration' => $item->duration,
                    'duration_unit' => $item->duration_unit,
                    'status' => $item->status,
                ])->values()->all(),
            ])->values()->all()),
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

    /**
     * Only RELEASED radiology reports are ever presented as final results on
     * a patient's clinical timeline (Phase 5, Step 44, Part 17-18) — mirrors
     * releasedLaboratoryResults() exactly. No duplicate patient-history
     * system was created: every record already lives in its own
     * authoritative RadiologyReport row, reached through the encounter's own
     * radiologyOrders relation. Uses RadiologyResultResource (also used by
     * the clinical encounter page) rather than hand-rolling the same field
     * shape twice.
     *
     * @return list<array<string, mixed>>
     */
    protected function releasedRadiologyResults(Request $request): array
    {
        return $this->radiologyOrders
            ->flatMap(fn ($order) => $order->items)
            ->flatMap(fn ($item) => $item->radiologyAppointments->each(fn (RadiologyAppointment $appointment) => $appointment->setRelation('radiologyOrderItem', $item)))
            ->filter(fn (RadiologyAppointment $appointment) => $appointment->report?->status === RadiologyReport::STATUS_RELEASED)
            ->map(fn (RadiologyAppointment $appointment) => (new RadiologyResultResource($appointment))->resolve($request))
            ->values()
            ->all();
    }
}
