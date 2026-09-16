<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Payment (Phase 7, Step 57) — used for the payment list, detail,
 * and receipt pages.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'receipt_number' => $this->receipt_number,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'status' => $this->status,
            'notes' => $this->notes,
            'hospital' => $this->whenLoaded('hospital', fn () => $this->hospital ? [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
            ] : null),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'middle_name' => $this->patient->middle_name,
                'last_name' => $this->patient->last_name,
            ] : null),
            'patient_account' => $this->whenLoaded('patientAccount', fn () => $this->patientAccount ? [
                'id' => $this->patientAccount->id,
                'account_number' => $this->patientAccount->account_number,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount' => $this->invoice->total_amount,
            ] : null),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'invoice_id' => $allocation->invoice_id,
                'amount' => $allocation->amount,
            ])->values()),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy ? [
                'id' => $this->receivedBy->id,
                'name' => $this->receivedBy->UserName,
            ] : null),
            'failed_by' => $this->whenLoaded('failedBy', fn () => $this->failedBy ? [
                'id' => $this->failedBy->id,
                'name' => $this->failedBy->UserName,
            ] : null),
            'failure_reason' => $this->failure_reason,
            'reversal' => $this->whenLoaded('reversal', fn () => $this->reversal ? (new PaymentReversalResource($this->reversal))->resolve($request) : null),
            'refunds' => $this->whenLoaded('refunds', fn () => PaymentRefundResource::collection($this->refunds)->resolve($request)),
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'failed_at' => optional($this->failed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
