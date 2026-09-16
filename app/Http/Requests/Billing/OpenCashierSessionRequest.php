<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation for opening a cashier session (Phase 7, Step 59).
 * Policy authorization (hospital scoping + billing-cashier.open permission)
 * is enforced in CashierSessionController::store().
 */
class OpenCashierSessionRequest extends FormRequest
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
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
