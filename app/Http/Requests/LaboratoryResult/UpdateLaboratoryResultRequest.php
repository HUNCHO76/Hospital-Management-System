<?php

namespace App\Http\Requests\LaboratoryResult;

use App\Models\LaboratoryResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.update' permission plus hospital scoping is
     * enforced by LaboratoryResultPolicy in the controller. Only bounds/
     * shape validation lives here — which of value_numeric/value_text/
     * value_code is actually required, and against which configured
     * options, depends on this result's own result_type, so
     * LaboratoryResultService::validateValue() remains the single
     * authoritative check (never duplicated here).
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
            'value_numeric' => ['nullable', 'numeric'],
            'value_text' => ['nullable', 'string', 'max:4000'],
            'value_code' => ['nullable', 'string', 'max:255'],
            'abnormal_flag' => ['nullable', 'string', Rule::in(LaboratoryResult::ABNORMAL_FLAGS)],
            'result_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
