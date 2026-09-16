<?php

namespace App\Http\Resources;

use App\Models\StockBatch;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A StockItem — "this medication, held at this hospital" (Phase 6, Step 49).
 * Dual-mode: on the searchable list (StockSearchService::itemsQuery()), the
 * total/available quantity and nearest expiry arrive as selected raw SQL
 * aggregate columns; on the detail page, they are computed in PHP from the
 * eager-loaded `batches` relation instead (small, single-item collection —
 * no N+1 risk). Never queries anything itself.
 *
 * @mixin StockItem
 */
class StockItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usingBatches = $this->relationLoaded('batches');
        $batches = $usingBatches ? $this->batches : collect();

        $totalQuantity = $usingBatches ? (int) $batches->sum('quantity') : (int) ($this->total_quantity ?? 0);
        $availableQuantity = $usingBatches
            ? (int) $batches->filter(fn (StockBatch $batch) => $batch->isDispensable())->sum('quantity')
            : (int) ($this->available_quantity ?? 0);
        $nearestExpiry = $usingBatches
            ? optional($batches->filter(fn (StockBatch $batch) => $batch->status === StockBatch::STATUS_ACTIVE && $batch->quantity > 0)->sortBy('expiry_date')->first())->expiry_date
            : $this->nearest_expiry_date;

        return [
            'id' => $this->id,
            'hospital' => $this->whenLoaded('hospital', fn () => $this->hospital ? [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
            ] : null),
            'medication' => $this->whenLoaded('medication', fn () => $this->medication ? [
                'id' => $this->medication->id,
                'code' => $this->medication->code,
                'generic_name' => $this->medication->generic_name,
                'brand_name' => $this->medication->brand_name,
                'form' => $this->medication->form,
                'unit' => $this->medication->unit,
                'category' => $this->medication->category,
            ] : null),
            'reorder_level' => $this->reorder_level,
            'maximum_level' => $this->maximum_level,
            'status' => $this->status,
            'total_quantity' => $totalQuantity,
            'available_quantity' => $availableQuantity,
            'is_low_stock' => $availableQuantity <= $this->reorder_level,
            'nearest_expiry_date' => $nearestExpiry ? (is_string($nearestExpiry) ? $nearestExpiry : $nearestExpiry->toDateString()) : null,
            'batches' => $usingBatches ? StockBatchResource::collection($batches->sortBy('expiry_date')->values())->resolve($request) : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
