<?php

namespace App\Http\Requests\PrescriptionOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReturnPrescriptionOrderRequest extends FormRequest
{
    /**
     * The 'pharmacy-verification.return' permission, hospital scoping, and
     * self-verification protection are enforced by PrescriptionOrderPolicy
     * in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A reason is always mandatory (spec §13) — unlike RadiologyReport's
     * coded RETURN_REASONS list, spec §13 gives only a free-text example
     * ("Quantity does not match prescribed duration.") and never defines a
     * fixed reason vocabulary for prescriptions, so this stays a single
     * required free-text field rather than inventing an enum the spec never
     * asked for.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
