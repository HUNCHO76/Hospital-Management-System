<?php

namespace App\Http\Resources;

use App\Models\RadiologyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full reporting-page payload (Phase 5, Step 42, Part 21): report
 * content plus the patient/encounter/order/procedure/examination context a
 * reporting practitioner needs, all read live from the appointment's own
 * relation chain — never snapshotted onto the report row itself (Part 3/7).
 * Every nested section is only included when its relation is eager-loaded,
 * mirroring RadiologyAppointmentResource's own convention exactly.
 *
 * @mixin RadiologyReport
 */
class RadiologyReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $appointment = $this->relationLoaded('radiologyAppointment') ? $this->radiologyAppointment : null;
        $item = $appointment?->relationLoaded('radiologyOrderItem') ? $appointment->radiologyOrderItem : null;
        $order = $item?->relationLoaded('radiologyOrder') ? $item->radiologyOrder : null;
        $encounter = $order?->relationLoaded('clinicalEncounter') ? $order->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;
        $procedure = $item?->relationLoaded('radiologyProcedure') ? $item->radiologyProcedure : null;

        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'radiology_appointment_id' => $this->radiology_appointment_id,
            'status' => $this->status,
            'technique' => $this->technique,
            'findings' => $this->findings,
            'impression' => $this->impression,
            'recommendations' => $this->recommendations,
            'reporting_practitioner' => $this->whenLoaded('reportingPractitioner', fn () => $this->reportingPractitioner ? [
                'id' => $this->reportingPractitioner->id,
                'name' => $this->reportingPractitioner->staff?->user?->UserName,
                'specialization' => $this->reportingPractitioner->specialization,
            ] : null),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->only(['id', 'UserName'])),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->only(['id', 'UserName'])),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'verifying_practitioner' => $this->whenLoaded('verifyingPractitioner', fn () => $this->verifyingPractitioner ? [
                'id' => $this->verifyingPractitioner->id,
                'name' => $this->verifyingPractitioner->staff?->user?->UserName,
                'specialization' => $this->verifyingPractitioner->specialization,
            ] : null),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->only(['id', 'UserName'])),
            'verified_at' => optional($this->verified_at)->toIso8601String(),
            'verification_notes' => $this->verification_notes,
            'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy?->only(['id', 'UserName'])),
            'returned_at' => optional($this->returned_at)->toIso8601String(),
            'return_reason' => $this->return_reason,
            'return_comments' => $this->return_comments,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'examination' => $appointment ? [
                'id' => $appointment->id,
                'modality' => $appointment->modality,
                'resource_name' => $appointment->resource_name,
                'status' => $appointment->status,
                'performed_by' => $appointment->relationLoaded('performedBy') ? $appointment->performedBy?->only(['id', 'UserName']) : null,
                'started_at' => optional($appointment->started_at)->toIso8601String(),
                'completed_at' => optional($appointment->completed_at)->toIso8601String(),
                'technical_notes' => $appointment->technical_notes,
                'technical_details' => $appointment->technical_details,
            ] : null,
            'item' => $item ? [
                'id' => $item->id,
                'procedure_code' => $item->procedure_code,
                'procedure_name' => $item->procedure_name,
                'modality_snapshot' => $item->modality_snapshot,
                'body_site_snapshot' => $item->body_site_snapshot,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'priority' => $order->priority,
                'clinical_indication' => $order->clinical_indication,
                'ordered_by' => $order->relationLoaded('orderedBy') ? $order->orderedBy?->only(['id', 'UserName']) : null,
                'ordered_at' => optional($order->ordered_at)->toIso8601String(),
            ] : null,
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
                'started_at' => optional($encounter->started_at)->toIso8601String(),
            ] : null,
            'patient' => $patient ? [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
                'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
                'gender' => $patient->gender,
            ] : null,
            'procedure' => $procedure ? [
                'laterality' => $procedure->laterality,
                'body_sites' => $procedure->body_sites,
            ] : null,
        ];
    }
}
