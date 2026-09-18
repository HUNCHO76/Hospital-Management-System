<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Invoice (Phase 7, Step 55) — used for both the invoice list and
 * the invoice detail page. `charges` (the invoice's own line items — see
 * create_invoices_table's own docblock for why there is no separate invoice
 * item resource/table) is only included when eager-loaded.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'invoice_date' => optional($this->invoice_date)->toDateString(),
            'subtotal' => $this->subtotal,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
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
            'charges' => $this->whenLoaded('charges', fn () => ChargeResource::collection($this->charges)->resolve()),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->UserName,
            ] : null),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->UserName,
            ] : null),
            'cancellation_reason' => $this->cancellation_reason,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
