<?php

namespace App\Http\Resources;

use App\Models\PaymentRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentRefund
 */
class PaymentRefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'refund_number' => $this->refund_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'refund_method' => $this->refund_method,
            'refund_reference' => $this->refund_reference,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'processed_at' => optional($this->processed_at)->toIso8601String(),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'payment_number' => $this->payment->payment_number,
                'amount' => $this->payment->amount,
            ] : null),
            'processed_by' => $this->whenLoaded('processedBy', fn () => $this->processedBy ? [
                'id' => $this->processedBy->id,
                'name' => $this->processedBy->UserName,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
