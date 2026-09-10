<?php

namespace App\Http\Resources;

use App\Models\LaboratoryReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The printable/downloadable laboratory report (Phase 4, Step 35) —
 * combines the frozen `results_snapshot` (exactly what was released, never
 * re-derived from potentially-since-changed live data) with live
 * patient/order/sample header information (deliberately NOT snapshotted —
 * see LaboratoryReportService::buildSnapshot()'s own docblock for why).
 *
 * @mixin LaboratoryReport
 */
class LaboratoryReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->laboratoryOrder;
        $encounter = $order?->clinicalEncounter;
        $patient = $encounter?->patient;
        $department = $encounter?->department;
        $hospital = $department?->hospital;

        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'version' => $this->version,
            'status' => $this->status,
            'results' => $this->groupedResults(),
            'patient' => $patient ? [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
                'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
                'gender' => $patient->gender,
            ] : null,
            'hospital' => $hospital ? [
                'name' => $hospital->name,
                'phone' => $hospital->phone,
                'email' => $hospital->email,
                'address' => $hospital->address,
            ] : null,
            'department' => $department ? ['id' => $department->id, 'name' => $department->name] : null,
            'laboratory_order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'priority' => $order->priority,
                'ordered_at' => optional($order->ordered_at)->toIso8601String(),
                'ordered_by' => $order->relationLoaded('orderedBy') ? $order->orderedBy?->UserName : null,
                'clinical_indication' => $order->clinical_indication,
            ] : null,
            'encounter' => $encounter ? ['id' => $encounter->id, 'encounter_number' => $encounter->encounter_number] : null,
            'released_by' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy?->UserName),
            'released_at' => optional($this->released_at)->toIso8601String(),
            'printed_by' => $this->whenLoaded('printedBy', fn () => $this->printedBy?->UserName),
            'printed_at' => optional($this->printed_at)->toIso8601String(),
            'downloaded_by' => $this->whenLoaded('downloadedBy', fn () => $this->downloadedBy?->UserName),
            'downloaded_at' => optional($this->downloaded_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * The snapshot, grouped by sample so the report never mixes results
     * from different specimens (spec Part 13).
     *
     * @return list<array{sample_number: string|null, specimen_type: string|null, results: list<array<string, mixed>>}>
     */
    protected function groupedResults(): array
    {
        return collect($this->results_snapshot)
            ->groupBy('sample_number')
            ->map(fn ($results, $sampleNumber) => [
                'sample_number' => $sampleNumber === '' ? null : $sampleNumber,
                'specimen_type' => $results->first()['specimen_type'] ?? null,
                'results' => $results->values()->all(),
            ])
            ->values()
            ->all();
    }
}
