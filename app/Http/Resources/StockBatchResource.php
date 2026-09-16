<?php

namespace App\Http\Resources;

use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single StockBatch (Phase 6, Step 49) — used on the stock item detail
 * page's batch table. `is_expired`/`is_dispensable` are computed from the
 * model's own methods (never re-derived here), the same authoritative check
 * StockService::allocateFefo() itself relies on.
 *
 * @mixin StockBatch
 */
class StockBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'manufacture_date' => optional($this->manufacture_date)->toDateString(),
            'expiry_date' => $this->expiry_date->toDateString(),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'supplier_name' => $this->supplier_name,
            'status' => $this->status,
            'is_expired' => $this->isExpired(),
            'is_expiring_soon' => ! $this->isExpired() && $this->expiry_date->lte(now()->addDays((int) config('pharmacy.stock_expiry_warning_days'))),
            'is_dispensable' => $this->isDispensable(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
