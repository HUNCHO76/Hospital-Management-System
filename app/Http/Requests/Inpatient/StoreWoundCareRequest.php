<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class StoreWoundCareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.wound-care-manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'performed_at' => ['required', 'date'],
            'site' => ['required', 'string', 'max:150'],
            'indication' => ['required', 'string', 'max:2000'],
            'assessment' => ['required', 'string', 'max:5000'],
            'appearance' => ['nullable', 'string', 'max:2000'],
            'dressing_performed' => ['nullable', 'string', 'max:2000'],
            'materials_used' => ['nullable', 'string', 'max:2000'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'patient_response' => ['nullable', 'string', 'max:2000'],
            'complications' => ['nullable', 'string', 'max:2000'],
            'concerning_findings' => ['boolean'],
            'next_dressing_due_at' => ['nullable', 'date'],
            'follow_up_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
