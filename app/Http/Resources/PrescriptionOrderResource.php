<?php

namespace App\Http\Resources;

use App\Models\PrescriptionOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Used both nested inline on the encounter page and standalone on the
 * Pharmacy dashboard/worklist/detail pages — mirrors RadiologyOrderResource
 * exactly. The `patient`/`encounter` fields are only included when the
 * caller has eager-loaded clinicalEncounter.patient.
 *
 * @mixin PrescriptionOrder
 */
class PrescriptionOrderResource extends JsonResource
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
            'prescription_number' => $this->prescription_number,
            'clinical_notes' => $this->clinical_notes,
            'status' => $this->status,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => (new PrescriptionOrderItemResource($item))->resolve($request))->all(),
            ),
            'prescriber' => $this->whenLoaded('prescriber', fn () => $this->prescriber ? [
                'id' => $this->prescriber->id,
                'name' => $this->prescriber->UserName,
            ] : null),
            'prescribed_at' => optional($this->prescribed_at)->toIso8601String(),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->UserName,
            ] : null),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            // Phase 6, Step 47: the pharmacy-owned verification review, so
            // the prescriber can see their own prescription's status/return
            // reason inline on the encounter page without a separate page.
            'verification' => $this->whenLoaded('verification', fn () => $this->verification ? [
                'status' => $this->verification->status,
                'submitted_at' => optional($this->verification->submitted_at)->toIso8601String(),
                'verified_by' => $this->verification->relationLoaded('verifiedBy') && $this->verification->verifiedBy
                    ? ['id' => $this->verification->verifiedBy->id, 'name' => $this->verification->verifiedBy->UserName]
                    : null,
                'verified_at' => optional($this->verification->verified_at)->toIso8601String(),
                'verification_notes' => $this->verification->verification_notes,
                'returned_by' => $this->verification->relationLoaded('returnedBy') && $this->verification->returnedBy
                    ? ['id' => $this->verification->returnedBy->id, 'name' => $this->verification->returnedBy->UserName]
                    : null,
                'returned_at' => optional($this->verification->returned_at)->toIso8601String(),
                'return_reason' => $this->verification->return_reason,
            ] : null),
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
            'department' => $encounter && $encounter->relationLoaded('department') && $encounter->department ? [
                'id' => $encounter->department->id,
                'name' => $encounter->department->name,
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
