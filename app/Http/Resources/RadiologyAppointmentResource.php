<?php

namespace App\Http\Resources;

use App\Models\RadiologyAppointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Used on the scheduling dashboard, the operational worklist, and the
 * reception/preparation dashboard (Phase 5, Steps 39-40). `item`/`order`/
 * `patient` are only included when the caller has eager-loaded
 * radiologyOrderItem.radiologyOrder.clinicalEncounter.patient.
 * `procedure` (preparation/contrast info) is read live from the catalog,
 * never snapshotted (Part 8 — instructions must always reflect the current
 * approved protocol, not what was configured at order time), and is only
 * included when radiologyOrderItem.radiologyProcedure is eager-loaded.
 *
 * @mixin RadiologyAppointment
 */
class RadiologyAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->relationLoaded('radiologyOrderItem') ? $this->radiologyOrderItem : null;
        $order = $item?->relationLoaded('radiologyOrder') ? $item->radiologyOrder : null;
        $encounter = $order?->relationLoaded('clinicalEncounter') ? $order->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;
        $procedure = $item?->relationLoaded('radiologyProcedure') ? $item->radiologyProcedure : null;

        return [
            'id' => $this->id,
            'radiology_order_item_id' => $this->radiology_order_item_id,
            'modality' => $this->modality,
            'resource_name' => $this->resource_name,
            'scheduled_start' => optional($this->scheduled_start)->toIso8601String(),
            'scheduled_end' => optional($this->scheduled_end)->toIso8601String(),
            'status' => $this->status,
            'notes' => $this->notes,
            'scheduled_by' => $this->whenLoaded('scheduledBy', fn () => $this->scheduledBy ? [
                'id' => $this->scheduledBy->id,
                'name' => $this->scheduledBy->UserName,
            ] : null),
            'confirmed_at' => optional($this->confirmed_at)->toIso8601String(),
            'rescheduled_at' => optional($this->rescheduled_at)->toIso8601String(),
            'reschedule_reason' => $this->reschedule_reason,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'arrived_at' => optional($this->arrived_at)->toIso8601String(),
            'arrived_by' => $this->whenLoaded('arrivedBy', fn () => $this->arrivedBy?->only(['id', 'UserName'])),
            'verified_at' => optional($this->verified_at)->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->only(['id', 'UserName'])),
            'instructions_given_at' => optional($this->instructions_given_at)->toIso8601String(),
            'preparation_completed_at' => optional($this->preparation_completed_at)->toIso8601String(),
            'preparation_notes' => $this->preparation_notes,
            'preparation_waived_at' => optional($this->preparation_waived_at)->toIso8601String(),
            'preparation_waived_reason' => $this->preparation_waived_reason,
            'mri_screening_completed_at' => optional($this->mri_screening_completed_at)->toIso8601String(),
            'mri_screening_flagged' => $this->mri_screening_flagged,
            'mri_screening_notes' => $this->mri_screening_notes,
            'contrast_reviewed_at' => optional($this->contrast_reviewed_at)->toIso8601String(),
            'consent_status' => $this->consent_status,
            'consent_recorded_at' => optional($this->consent_recorded_at)->toIso8601String(),
            'not_ready_reason' => $this->not_ready_reason,
            'ready_at' => optional($this->ready_at)->toIso8601String(),
            'ready_by' => $this->whenLoaded('readyBy', fn () => $this->readyBy?->only(['id', 'UserName'])),
            'performed_by' => $this->whenLoaded('performedBy', fn () => $this->performedBy?->only(['id', 'UserName'])),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->only(['id', 'UserName'])),
            'technical_notes' => $this->technical_notes,
            'technical_details' => $this->technical_details,
            'aborted_at' => optional($this->aborted_at)->toIso8601String(),
            'aborted_by' => $this->whenLoaded('abortedBy', fn () => $this->abortedBy?->only(['id', 'UserName'])),
            'abort_reason' => $this->abort_reason,
            'images' => $this->whenLoaded('images', fn () => RadiologyImageResource::collection($this->images)->resolve($request)),
            'item' => $item ? [
                'id' => $item->id,
                'procedure_code' => $item->procedure_code,
                'procedure_name' => $item->procedure_name,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'priority' => $order->priority,
                'clinical_indication' => $order->clinical_indication,
            ] : null,
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
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
                'preparation_type' => $procedure->preparation_type,
                'preparation_instructions' => $procedure->preparation_instructions,
                'contrast_requirement' => $procedure->contrast_requirement,
                'contrast_type' => $procedure->contrast_type,
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
