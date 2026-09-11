<?php

namespace App\Http\Requests\RadiologyExamination;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartRadiologyExaminationRequest extends FormRequest
{
    /**
     * The 'radiology-examination.start' permission plus hospital scoping is
     * enforced by RadiologyAppointmentPolicy in the controller.
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
            // Optional — only present when staff are correcting the actual
            // room/equipment used away from what was originally scheduled.
            'resource_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
