<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class ResolvePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('patient-allergies.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
