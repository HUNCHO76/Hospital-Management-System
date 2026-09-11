<?php

namespace App\Http\Requests\RadiologyExamination;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRadiologyExaminationRequest extends FormRequest
{
    /**
     * The 'radiology-examination.complete' permission plus hospital scoping
     * is enforced by RadiologyAppointmentPolicy in the controller.
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
