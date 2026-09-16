<?php

namespace App\Http\Requests\PrescriptionOrder;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPrescriptionOrderRequest extends FormRequest
{
    /**
     * The 'pharmacy-verification.verify' permission, hospital scoping, and
     * self-verification protection are enforced by PrescriptionOrderPolicy
     * in the controller. A client-supplied verifier_id/facility_id is never
     * accepted — the verifier is always resolved server-side from the
     * authenticated user.
     */
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
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
