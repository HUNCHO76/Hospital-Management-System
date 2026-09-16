<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation for closing a cashier session (Phase 7, Step 59).
 * Policy authorization (session ownership + billing-cashier.close permission)
 * is enforced in CashierSessionController::close(). Variance-reason
 * requirement is checked in CashierSessionService::close() under a lock.
 */
class CloseCashierSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'actual_closing_balance' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'variance_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
