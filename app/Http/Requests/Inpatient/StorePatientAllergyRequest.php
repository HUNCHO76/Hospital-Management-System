<?php

namespace App\Http\Requests\Inpatient;

use App\Models\PatientAllergy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('patient-allergies.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'allergen' => ['required', 'string', 'max:150'],
            'medication_id' => ['nullable', 'integer', 'exists:medications,id'],
            'severity' => ['required', 'string', Rule::in(PatientAllergy::SEVERITIES)],
            'reaction' => ['nullable', 'string', 'max:255'],
        ];
    }
}
