<?php

namespace App\Http\Resources;

use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratorySample
 */
class LaboratorySampleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sample_number' => $this->sample_number,
            'laboratory_order_id' => $this->laboratory_order_id,
            'specimen_type' => $this->specimen_type,
            'container_type' => $this->container_type,
            'specimen_source' => $this->specimen_source,
            'status' => $this->status,
            'collection_site' => $this->collection_site,
            'collection_notes' => $this->collection_notes,
            'collected_by' => $this->whenLoaded('collectedBy', fn () => $this->collectedBy ? [
                'id' => $this->collectedBy->id,
                'name' => $this->collectedBy->UserName,
            ] : null),
            'collected_at' => optional($this->collected_at)->toIso8601String(),
            'labelled_at' => optional($this->labelled_at)->toIso8601String(),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy ? [
                'id' => $this->receivedBy->id,
                'name' => $this->receivedBy->UserName,
            ] : null),
            'received_at' => optional($this->received_at)->toIso8601String(),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy ? [
                'id' => $this->rejectedBy->id,
                'name' => $this->rejectedBy->UserName,
            ] : null),
            'rejected_at' => optional($this->rejected_at)->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'rejection_notes' => $this->rejection_notes,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'recollection_of_sample_id' => $this->recollection_of_sample_id,
            'recollection_of' => $this->whenLoaded('recollectionOf', fn () => $this->recollectionOf ? [
                'id' => $this->recollectionOf->id,
                'sample_number' => $this->recollectionOf->sample_number,
            ] : null),
            'recollections' => $this->whenLoaded(
                'recollections',
                fn () => $this->recollections->map(fn ($r) => ['id' => $r->id, 'sample_number' => $r->sample_number])->all(),
            ),
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'test_code' => $item->test_code,
                    'test_name' => $item->test_name,
                ])->all(),
            ),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'middle_name' => $this->patient->middle_name,
                'last_name' => $this->patient->last_name,
                'date_of_birth' => optional($this->patient->date_of_birth)->toDateString(),
                'gender' => $this->patient->gender,
            ] : null),
            'laboratory_order' => $this->whenLoaded('laboratoryOrder', fn () => $this->laboratoryOrder ? [
                'id' => $this->laboratoryOrder->id,
                'order_number' => $this->laboratoryOrder->order_number,
                'priority' => $this->laboratoryOrder->priority,
            ] : null),
            'processing_status' => $this->whenLoaded('processingRecords', fn () => $this->processingStatusSummary()),
            'processing_records' => $this->whenLoaded(
                'processingRecords',
                fn () => LaboratoryProcessingResource::collection($this->processingRecords)->resolve($request),
            ),
            'history' => $this->history(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * A single rolled-up processing status for this specimen as a whole
     * (Phase 4, Step 32) — used by the processing worklist, which lists
     * one row per specimen (spec §32's own example table). A rejected
     * record takes priority (it needs attention regardless of how far
     * other tests on the same specimen got); otherwise the summary is the
     * least-advanced non-rejected record's own status, mirroring
     * LaboratoryOrder::statusFromItemProgress()'s exact "never imply
     * further along than the least-progressed one" rule.
     */
    protected function processingStatusSummary(): ?string
    {
        $records = $this->processingRecords;

        if ($records->isEmpty()) {
            return null;
        }

        if ($records->contains(fn ($r) => $r->status === LaboratoryProcessing::STATUS_REJECTED)) {
            return LaboratoryProcessing::STATUS_REJECTED;
        }

        $progress = [
            LaboratoryProcessing::STATUS_RECEIVED => 0,
            LaboratoryProcessing::STATUS_ACCEPTED => 1,
            LaboratoryProcessing::STATUS_ASSIGNED => 2,
            LaboratoryProcessing::STATUS_IN_PROGRESS => 3,
            LaboratoryProcessing::STATUS_PROCESSING_COMPLETE => 4,
            LaboratoryProcessing::STATUS_RESULT_PENDING => 4,
        ];

        $lowest = $records->map(fn ($r) => $progress[$r->status] ?? 0)->min();

        return array_search($lowest, $progress, true) ?: LaboratoryProcessing::STATUS_RECEIVED;
    }

    /**
     * A simple derived timeline from the sample's own lifecycle timestamps
     * (spec §37-38) — the full forensic audit trail lives in the existing
     * Audit Log viewer; this is only the at-a-glance traceability view.
     *
     * @return list<array{event: string, at: string, actor: string|null, reason: string|null}>
     */
    protected function history(): array
    {
        $entries = [
            ['event' => 'created', 'at' => $this->created_at, 'actor' => null, 'reason' => null],
            ['event' => 'collected', 'at' => $this->collected_at, 'actor' => $this->collectedBy?->UserName, 'reason' => null],
            ['event' => 'labelled', 'at' => $this->labelled_at, 'actor' => null, 'reason' => null],
            ['event' => 'received', 'at' => $this->received_at, 'actor' => $this->receivedBy?->UserName, 'reason' => null],
            ['event' => 'rejected', 'at' => $this->rejected_at, 'actor' => $this->rejectedBy?->UserName, 'reason' => $this->rejection_reason],
            ['event' => 'cancelled', 'at' => $this->cancelled_at, 'actor' => $this->cancelledBy?->UserName, 'reason' => $this->cancellation_reason],
        ];

        return collect($entries)
            ->filter(fn ($entry) => $entry['at'] !== null)
            ->sortBy('at')
            ->map(fn ($entry) => [...$entry, 'at' => $entry['at']->toIso8601String()])
            ->values()
            ->all();
    }
}
