<?php

namespace App\Http\Requests\Diagnosis;

use App\Models\EncounterDiagnosis;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeDiagnosisStatusRequest extends FormRequest
{
    /**
     * The 'diagnosis.status' permission plus hospital/ownership scoping is
     * enforced by EncounterDiagnosisPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'cancelled' is deliberately excluded — cancellation is its own action
     * requiring a mandatory reason (see CancelEncounterDiagnosisRequest).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([EncounterDiagnosis::STATUS_CONFIRMED, EncounterDiagnosis::STATUS_RESOLVED])],
        ];
    }
}
