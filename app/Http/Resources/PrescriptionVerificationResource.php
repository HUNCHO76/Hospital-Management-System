<?php

namespace App\Http\Resources;

use App\Models\Medication;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full verification-review payload (Phase 6, Step 47) — the
 * prescription's own content (via PrescriptionOrderItemResource) plus the
 * patient/encounter/prescriber context a verifier needs, plus this review's
 * own PrescriptionVerification status/metadata, plus lightweight safety
 * flags (spec §12) computed here rather than a separate framework: an
 * inactive medication, or the same medication already active on another of
 * this patient's prescriptions. Every nested section is only included when
 * its relation is eager-loaded, mirroring RadiologyReportResource's own
 * convention exactly.
 *
 * @mixin PrescriptionOrder
 */
class PrescriptionVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $encounter = $this->relationLoaded('clinicalEncounter') ? $this->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;
        $verification = $this->relationLoaded('verification') ? $this->verification : null;

        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'status' => $this->status,
            'clinical_notes' => $this->clinical_notes,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn (PrescriptionOrderItem $item) => [
                    ...(new PrescriptionOrderItemResource($item))->resolve($request),
                    'flags' => $this->flagsFor($item, $patient),
                ])->all(),
            ),
            'prescriber' => $this->whenLoaded('prescriber', fn () => $this->prescriber ? [
                'id' => $this->prescriber->id,
                'name' => $this->prescriber->UserName,
            ] : null),
            'prescribed_at' => optional($this->prescribed_at)->toIso8601String(),
            'verification' => $verification ? [
                'status' => $verification->status,
                'submitted_by' => $verification->relationLoaded('submittedBy') && $verification->submittedBy
                    ? ['id' => $verification->submittedBy->id, 'name' => $verification->submittedBy->UserName]
                    : null,
                'submitted_at' => optional($verification->submitted_at)->toIso8601String(),
                'verified_by' => $verification->relationLoaded('verifiedBy') && $verification->verifiedBy
                    ? ['id' => $verification->verifiedBy->id, 'name' => $verification->verifiedBy->UserName]
                    : null,
                'verified_at' => optional($verification->verified_at)->toIso8601String(),
                'verification_notes' => $verification->verification_notes,
                'returned_by' => $verification->relationLoaded('returnedBy') && $verification->returnedBy
                    ? ['id' => $verification->returnedBy->id, 'name' => $verification->returnedBy->UserName]
                    : null,
                'returned_at' => optional($verification->returned_at)->toIso8601String(),
                'return_reason' => $verification->return_reason,
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
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
            ] : null,
            'department' => $encounter && $encounter->relationLoaded('department') && $encounter->department ? [
                'id' => $encounter->department->id,
                'name' => $encounter->department->name,
            ] : null,
        ];
    }

    /**
     * Safety flags a verifier should notice (spec §12) — a workflow/data
     * check, never a clinical decision. 'medication_inactive': the catalog
     * entry has since been deactivated. 'duplicate_medication': the same
     * medication is already active on another of this patient's
     * prescriptions.
     *
     * @return list<string>
     */
    protected function flagsFor(PrescriptionOrderItem $item, ?object $patient): array
    {
        $flags = [];

        if ($item->relationLoaded('medication') && $item->medication?->status !== Medication::STATUS_ACTIVE) {
            $flags[] = 'medication_inactive';
        }

        if ($patient !== null) {
            $duplicateExists = PrescriptionOrderItem::query()
                ->where('medication_id', $item->medication_id)
                ->where('status', PrescriptionOrderItem::STATUS_ACTIVE)
                ->where('id', '!=', $item->id)
                ->whereHas(
                    'prescriptionOrder.clinicalEncounter',
                    fn ($query) => $query->where('patient_id', $patient->id),
                )
                ->exists();

            if ($duplicateExists) {
                $flags[] = 'duplicate_medication';
            }
        }

        return $flags;
    }
}
