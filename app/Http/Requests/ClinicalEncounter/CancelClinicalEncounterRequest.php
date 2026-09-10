<?php

namespace App\Http\Requests\ClinicalEncounter;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelClinicalEncounterRequest extends FormRequest
{
    /**
     * The 'clinical-encounters.cancel' permission plus hospital scoping is
     * enforced by ClinicalEncounterPolicy in the controller.
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
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
