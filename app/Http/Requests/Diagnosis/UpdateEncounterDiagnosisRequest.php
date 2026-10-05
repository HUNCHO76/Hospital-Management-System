<?php

namespace App\Http\Requests\Diagnosis;

use App\Models\DiagnosisCode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEncounterDiagnosisRequest extends FormRequest
{
    /**
     * The 'diagnosis.update' permission plus hospital/ownership scoping is
     * enforced by EncounterDiagnosisPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * diagnosis_type is deliberately not editable here — the only way a
     * diagnosis becomes primary is the explicit setPrimary action (spec
     * §26), never a plain field edit.
     *
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
            'nhif_disease_code' => ['nullable', 'string', 'max:20', 'required_with:nhif_disease_name,nhif_icd_version_code'],
            'nhif_disease_name' => ['nullable', 'string', 'max:255', 'required_with:nhif_disease_code'],
            'nhif_icd_version_code' => ['nullable', 'string', 'max:10', 'required_with:nhif_disease_code'],
        ];
    }
}
