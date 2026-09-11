<?php

namespace App\Http\Requests\RadiologyReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRadiologyReportRequest extends FormRequest
{
    /**
     * The 'radiology-results.create' permission plus hospital scoping is
     * enforced by RadiologyReportPolicy in the controller.
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
            // A row the client points at, not an authoritative identity
            // field (Part 29) — every relationship (patient/encounter/
            // order/practitioner) is then re-derived server-side from this
            // row, never trusted directly from the request.
            'radiology_appointment_id' => ['required', Rule::exists('radiology_appointments', 'id')],
            'technique' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:8000'],
            'impression' => ['nullable', 'string', 'max:4000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
