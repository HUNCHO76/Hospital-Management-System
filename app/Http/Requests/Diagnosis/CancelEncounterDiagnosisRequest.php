<?php

namespace App\Http\Requests\Diagnosis;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelEncounterDiagnosisRequest extends FormRequest
{
    /**
     * The 'diagnosis.cancel' permission plus hospital/ownership scoping is
     * enforced by EncounterDiagnosisPolicy in the controller.
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
