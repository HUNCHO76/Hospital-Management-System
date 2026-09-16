<?php

namespace App\Http\Resources;

use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single ledger row (Phase 6, Step 49) — read-only projection of an
 * immutable StockTransaction, used on the stock item detail page's
 * transaction history section and (Phase 6, Step 50) the cross-medication
 * stock movement report.
 *
 * @mixin StockTransaction
 */
class StockTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'transaction_type' => $this->transaction_type,
            'quantity' => $this->quantity,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reason' => $this->reason,
            // Only present when the caller eager-loads stockItem.medication
            // (Step 50's cross-medication movement report) — the stock item
            // detail page never does, since the medication is already
            // established by the page it's shown on.
            'medication_name' => $this->whenLoaded('stockItem', fn () => $this->stockItem->relationLoaded('medication') && $this->stockItem->medication
                ? $this->stockItem->medication->generic_name
                : null),
            'stock_batch' => $this->whenLoaded('stockBatch', fn () => $this->stockBatch ? [
                'id' => $this->stockBatch->id,
                'batch_number' => $this->stockBatch->batch_number,
            ] : null),
            'performed_by' => $this->whenLoaded('performedBy', fn () => $this->performedBy ? [
                'id' => $this->performedBy->id,
                'name' => $this->performedBy->UserName,
            ] : null),
            'occurred_at' => optional($this->occurred_at)->toIso8601String(),
        ];
    }
}
