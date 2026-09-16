<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Service catalog entry (Phase 7, Step 53). `is_referenced` is read from
 * an eager-loaded `prices_count` (the caller must `withCount('prices')`),
 * never `Service::isReferenced()` directly — that would re-query once per
 * row on a list page.
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'is_billable' => $this->is_billable,
            'status' => $this->status,
            'serviceable_type' => $this->serviceable_type,
            'serviceable_id' => $this->serviceable_id,
            'is_referenced' => ($this->prices_count ?? 0) > 0,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
