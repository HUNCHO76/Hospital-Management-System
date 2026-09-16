<?php

namespace App\Http\Resources;

use App\Models\PaymentReversal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentReversal
 */
class PaymentReversalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reversal_number' => $this->reversal_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'reversed_at' => optional($this->reversed_at)->toIso8601String(),
            'original_payment' => $this->whenLoaded('originalPayment', fn () => $this->originalPayment ? [
                'id' => $this->originalPayment->id,
                'payment_number' => $this->originalPayment->payment_number,
                'amount' => $this->originalPayment->amount,
                'payment_method' => $this->originalPayment->payment_method,
            ] : null),
            'reversed_by' => $this->whenLoaded('reversedBy', fn () => $this->reversedBy ? [
                'id' => $this->reversedBy->id,
                'name' => $this->reversedBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
