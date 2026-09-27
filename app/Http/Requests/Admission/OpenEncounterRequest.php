<?php

namespace App\Http\Requests\Admission;

use Illuminate\Foundation\Http\FormRequest;

class OpenEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient.encounter.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'integer', 'exists:practitioners,id'],
        ];
    }
}
