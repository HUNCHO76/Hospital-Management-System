<?php

namespace App\Http\Resources;

use App\Models\RadiologyOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row on the "Orders Awaiting Scheduling" queue (Phase 5, Step 39, Part
 * 13) — just enough patient/order/procedure/priority context for a
 * scheduler to identify and act on it, per Part 10's "do not expose
 * unnecessary sensitive clinical information" instruction (no full clinical
 * indication text is required here, but it is included since Part 10 also
 * explicitly lists it as needed to safely schedule urgent work).
 *
 * @mixin RadiologyOrderItem
 */
class UnscheduledRadiologyOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('radiologyOrder') ? $this->radiologyOrder : null;
        $encounter = $order?->relationLoaded('clinicalEncounter') ? $order->clinicalEncounter : null;
        $patient = $encounter?->relationLoaded('patient') ? $encounter->patient : null;

        return [
            'id' => $this->id,
            'procedure_code' => $this->procedure_code,
            'procedure_name' => $this->procedure_name,
            'modality_snapshot' => $this->modality_snapshot,
            'body_site_snapshot' => $this->body_site_snapshot,
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'priority' => $order->priority,
                'clinical_indication' => $order->clinical_indication,
                'ordered_at' => optional($order->ordered_at)->toIso8601String(),
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
        ];
    }
}
