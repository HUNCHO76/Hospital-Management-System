<?php

namespace App\Http\Requests\Billing;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation only (Phase 7, Step 58) — PaymentRefundService::
 * create() re-validates the refundable balance and duplicate-reference
 * protection under a row lock, so a stale form value here can never bypass
 * the service-layer guards.
 */
class StorePaymentRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'refund_method' => ['required', Rule::in(Payment::METHODS)],
            'refund_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
