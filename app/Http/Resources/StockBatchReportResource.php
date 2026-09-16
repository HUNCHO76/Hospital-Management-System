<?php

namespace App\Http\Resources;

use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A StockBatch row for the cross-medication batch/reconciliation reports
 * (Phase 6, Step 50, spec §7/§15) — unlike StockBatchResource (used on one
 * stock item's own detail page, where the medication is already known from
 * context), this always includes the medication/hospital identity since a
 * report row is read out of context.
 *
 * @mixin StockBatch
 */
class StockBatchReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stockItem = $this->relationLoaded('stockItem') ? $this->stockItem : null;
        $medication = $stockItem?->relationLoaded('medication') ? $stockItem->medication : null;

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
            'medication' => $medication ? [
                'id' => $medication->id,
                'code' => $medication->code,
                'generic_name' => $medication->generic_name,
                'brand_name' => $medication->brand_name,
                'form' => $medication->form,
                'unit' => $medication->unit,
            ] : null,
            'hospital' => $stockItem?->relationLoaded('hospital') && $stockItem->hospital ? [
                'id' => $stockItem->hospital->id,
                'name' => $stockItem->hospital->name,
            ] : null,
            'stock_item_id' => $this->stock_item_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
