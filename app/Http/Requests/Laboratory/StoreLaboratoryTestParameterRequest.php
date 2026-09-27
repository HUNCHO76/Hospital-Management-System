<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryTestParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryTestParameterRequest extends FormRequest
{
    /**
     * Authorization is enforced by LaboratoryTestParameterPolicy in the controller.
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
        $testId = $this->route('laboratoryTest')?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique(LaboratoryTestParameter::class, 'code')
                    ->where('laboratory_test_id', $testId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'data_type' => ['required', 'string', Rule::in(LaboratoryTestParameter::DATA_TYPES)],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            'options_json' => ['nullable', 'array'],
            'options_json.*' => ['string', 'max:100'],
            'abnormal_options' => ['nullable', 'array'],
            'abnormal_options.*' => ['string', 'max:100'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_calculated' => ['nullable', 'boolean'],
            'calculation_formula' => ['nullable', 'string', 'max:500'],
            'analyser_code' => ['nullable', 'string', 'max:50'],
            'loinc_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
