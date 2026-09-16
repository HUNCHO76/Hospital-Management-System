<?php

namespace App\Http\Resources;

use App\Models\DispensingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the dispensing report (Phase 6, Step 50, spec §13) — a single
 * DispensingItem with enough eager-loaded context (its parent Dispensing
 * event, the prescription/patient/facility chain) to stand alone outside
 * the dispensing worklist's own PrescriptionDispensingResource shape.
 *
 * @mixin DispensingItem
 */
class DispensingItemReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dispensing = $this->relationLoaded('dispensing') ? $this->dispensing : null;
        $order = $dispensing?->relationLoaded('prescriptionOrder') ? $dispensing->prescriptionOrder : null;
        $encounter = $order?->relationLoaded('clinicalEncounter') ? $order->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;
        $prescriptionItem = $this->relationLoaded('prescriptionOrderItem') ? $this->prescriptionOrderItem : null;

        return [
            'id' => $this->id,
            'dispensed_quantity' => $this->dispensed_quantity,
            'status' => $this->status,
            'notes' => $this->notes,
            'medication_name' => $prescriptionItem?->medication_name_snapshot,
            'prescribed_quantity' => $prescriptionItem?->quantity,
            'dispensing' => $dispensing ? [
                'id' => $dispensing->id,
                'dispensing_number' => $dispensing->dispensing_number,
                'status' => $dispensing->status,
                'completed_at' => optional($dispensing->completed_at)->toIso8601String(),
                'dispensed_by' => $dispensing->relationLoaded('dispensedBy') && $dispensing->dispensedBy
                    ? ['id' => $dispensing->dispensedBy->id, 'name' => $dispensing->dispensedBy->UserName]
                    : null,
            ] : null,
            'prescription' => $order ? [
                'id' => $order->id,
                'prescription_number' => $order->prescription_number,
            ] : null,
            'patient' => $patient ? [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
            ] : null,
            'department' => $encounter?->relationLoaded('department') && $encounter->department ? [
                'id' => $encounter->department->id,
                'name' => $encounter->department->name,
            ] : null,
        ];
    }
}
