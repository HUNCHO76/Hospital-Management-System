<?php

namespace App\Http\Resources;

use App\Models\DispensingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DispensingItem
 */
class DispensingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dispensing_id' => $this->dispensing_id,
            'prescription_order_item_id' => $this->prescription_order_item_id,
            'dispensed_quantity' => $this->dispensed_quantity,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
