<?php

namespace App\Http\Resources;

use App\Models\PrescriptionOrder;
use App\Services\DispensingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the prescription report (Phase 6, Step 50, spec §12) —
 * PrescriptionOrder plus its verification status and its dispensing
 * fulfillment status. The fulfillment status is computed via
 * DispensingService::fulfillmentStatus() (the same authoritative
 * calculation the dispensing worklist itself uses, spec §31) rather than
 * re-derived, and is only ever called for the current page's own rows
 * (bounded by pagination), never the whole table.
 *
 * @mixin PrescriptionOrder
 */
class PrescriptionReportResource extends JsonResource
{
    public function __construct(PrescriptionOrder $resource, protected DispensingService $dispensingService)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $encounter = $this->relationLoaded('clinicalEncounter') ? $this->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;

        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'status' => $this->status,
            'prescribed_at' => optional($this->prescribed_at)->toIso8601String(),
            'medication_count' => $this->relationLoaded('items') ? $this->items->count() : null,
            'prescriber' => $this->whenLoaded('prescriber', fn () => $this->prescriber ? [
                'id' => $this->prescriber->id,
                'name' => $this->prescriber->UserName,
            ] : null),
            'verification_status' => $this->relationLoaded('verification') ? $this->verification?->status : null,
            'dispensing_status' => $this->relationLoaded('items') ? $this->dispensingService->fulfillmentStatus($this->resource) : null,
            'patient' => $patient ? [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
            ] : null,
            'encounter' => $encounter ? [
                'id' => $encounter->id,
                'encounter_number' => $encounter->encounter_number,
            ] : null,
            'department' => $encounter?->relationLoaded('department') && $encounter->department ? [
                'id' => $encounter->department->id,
                'name' => $encounter->department->name,
            ] : null,
        ];
    }
}
