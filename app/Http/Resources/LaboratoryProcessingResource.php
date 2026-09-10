<?php

namespace App\Http\Resources;

use App\Models\LaboratoryProcessing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryProcessing
 */
class LaboratoryProcessingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_sample_id' => $this->laboratory_sample_id,
            'laboratory_order_item_id' => $this->laboratory_order_item_id,
            'status' => $this->status,
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'test_code' => $this->item->test_code,
                'test_name' => $this->item->test_name,
            ] : null),
            'bench' => $this->whenLoaded('bench', fn () => $this->bench ? [
                'id' => $this->bench->id,
                'name' => $this->bench->name,
                'code' => $this->bench->code,
            ] : null),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->UserName,
            ] : null),
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy ? [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->UserName,
            ] : null),
            'assigned_at' => optional($this->assigned_at)->toIso8601String(),
            'accepted_by' => $this->whenLoaded('acceptedBy', fn () => $this->acceptedBy ? [
                'id' => $this->acceptedBy->id,
                'name' => $this->acceptedBy->UserName,
            ] : null),
            'accepted_at' => optional($this->accepted_at)->toIso8601String(),
            'started_by' => $this->whenLoaded('startedBy', fn () => $this->startedBy ? [
                'id' => $this->startedBy->id,
                'name' => $this->startedBy->UserName,
            ] : null),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy ? [
                'id' => $this->completedBy->id,
                'name' => $this->completedBy->UserName,
            ] : null),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'processing_notes' => $this->processing_notes,
            'history' => $this->history(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * A simple derived timeline from this record's own lifecycle
     * timestamps (spec §27) — the full forensic audit trail lives in the
     * existing Audit Log viewer.
     *
     * @return list<array{event: string, at: string, actor: string|null}>
     */
    protected function history(): array
    {
        $entries = [
            ['event' => 'received', 'at' => $this->created_at, 'actor' => null],
            ['event' => 'accepted', 'at' => $this->accepted_at, 'actor' => $this->acceptedBy?->UserName],
            ['event' => 'assigned', 'at' => $this->assigned_at, 'actor' => $this->assignedBy?->UserName],
            ['event' => 'started', 'at' => $this->started_at, 'actor' => $this->startedBy?->UserName],
            ['event' => 'completed', 'at' => $this->completed_at, 'actor' => $this->completedBy?->UserName],
        ];

        return collect($entries)
            ->filter(fn ($entry) => $entry['at'] !== null)
            ->sortBy('at')
            ->map(fn ($entry) => [...$entry, 'at' => $entry['at']->toIso8601String()])
            ->values()
            ->all();
    }
}
