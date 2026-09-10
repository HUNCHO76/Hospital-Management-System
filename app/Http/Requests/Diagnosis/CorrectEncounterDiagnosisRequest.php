<?php

namespace App\Http\Requests\Diagnosis;

use App\Models\DiagnosisCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectEncounterDiagnosisRequest extends FormRequest
{
    /**
     * The 'diagnosis.correct' permission plus hospital/ownership scoping is
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
            'diagnosis_code_id' => [
                'sometimes',
                'integer',
                Rule::exists(DiagnosisCode::class, 'id')->where('status', DiagnosisCode::STATUS_ACTIVE),
            ],
            'clinical_note' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
