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
            // Single-value path (legacy single-analyte tests)
            'value_numeric' => ['nullable', 'numeric'],
            'value_text' => ['nullable', 'string', 'max:4000'],
            'value_code' => ['nullable', 'string', 'max:255'],
            'abnormal_flag' => ['nullable', 'string', Rule::in(LaboratoryResult::ABNORMAL_FLAGS)],
            'result_comment' => ['nullable', 'string', 'max:1000'],
            // Multi-parameter path (parameterized tests)
            'parameter_values' => ['nullable', 'array'],
            'parameter_values.*.parameter_id' => ['required_with:parameter_values', 'integer', 'min:1'],
            'parameter_values.*.value_numeric' => ['nullable', 'numeric'],
            'parameter_values.*.value_text' => ['nullable', 'string', 'max:4000'],
            'parameter_values.*.value_code' => ['nullable', 'string', 'max:255'],
            'parameter_values.*.result_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
