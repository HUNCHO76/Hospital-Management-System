<?php

namespace App\Http\Requests\RadiologyReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyRadiologyReportRequest extends FormRequest
{
    /**
     * The 'radiology-results.verify' permission, hospital scoping, and
     * self-verification protection are enforced by RadiologyReportPolicy in
     * the controller. A client-supplied verifier_id/facility_id/patient_id
     * is never accepted (Part 30) — the verifying practitioner is always
     * resolved server-side from the authenticated user.
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
