<?php

namespace App\Http\Requests\RadiologyReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseRadiologyResultRequest extends FormRequest
{
    /**
     * The 'radiology-results.release' permission, hospital scoping, and
     * report status are enforced by RadiologyReportPolicy/RadiologyReportService
     * in the controller. A client-supplied released_by/facility_id/patient_id
     * is never accepted (Part 26) — the releasing user is always resolved
     * server-side from the authenticated session.
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
            'release_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
