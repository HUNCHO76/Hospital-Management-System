<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation only (Phase 7, Step 58) — PaymentReversalService::
 * create() re-validates eligibility, status, and duplicate-reversal protection
 * under a row lock, so a stale form value here can never bypass the service.
 */
class StorePaymentReversalRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
