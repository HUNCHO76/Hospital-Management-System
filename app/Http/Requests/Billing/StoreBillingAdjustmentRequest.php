<?php

namespace App\Http\Requests\Billing;

use App\Models\BillingAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation only (Phase 7, Step 58) — BillingAdjustmentService::
 * create() re-validates invoice status under a row lock.
 */
class StoreBillingAdjustmentRequest extends FormRequest
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
            'type' => ['required', Rule::in(BillingAdjustment::TYPES)],
            'direction' => ['required', Rule::in(BillingAdjustment::DIRECTIONS)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
