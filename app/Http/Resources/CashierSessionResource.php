<?php

namespace App\Http\Resources;

use App\Models\CashierSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single CashierSession (Phase 7, Step 59).
 *
 * @mixin CashierSession
 */
class CashierSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_number' => $this->session_number,
            'status' => $this->status,
            'opening_balance' => $this->opening_balance,
            'expected_closing_balance' => $this->expected_closing_balance,
            'actual_closing_balance' => $this->actual_closing_balance,
            'variance' => $this->variance,
            'variance_reason' => $this->variance_reason,
            'notes' => $this->notes,
            'opened_at' => optional($this->opened_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'hospital' => $this->whenLoaded('hospital', fn () => $this->hospital ? [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
            ] : null),
            'cashier' => $this->whenLoaded('cashier', fn () => $this->cashier ? [
                'id' => $this->cashier->id,
                'name' => $this->cashier->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
