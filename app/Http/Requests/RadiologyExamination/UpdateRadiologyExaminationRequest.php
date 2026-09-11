<?php

namespace App\Http\Requests\RadiologyExamination;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRadiologyExaminationRequest extends FormRequest
{
    /**
     * The 'radiology-examination.update' permission plus hospital scoping
     * is enforced by RadiologyAppointmentPolicy in the controller. Only the
     * request's *shape* is validated here — the modality-specific
     * technical_details key whitelist depends on the appointment's own
     * modality, so that check lives in RadiologyExaminationService, mirroring
     * how LaboratoryResultService validates a result's value against its
     * own test's configured result_type rather than the FormRequest.
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
            'technical_notes' => ['nullable', 'string', 'max:4000'],
            'technical_details' => ['nullable', 'array'],
            'technical_details.*' => ['nullable', 'string', 'max:500'],
        ];
    }
}
