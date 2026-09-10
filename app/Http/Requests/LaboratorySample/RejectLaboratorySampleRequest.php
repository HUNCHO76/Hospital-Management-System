<?php

namespace App\Http\Requests\LaboratorySample;

use App\Models\LaboratorySample;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectLaboratorySampleRequest extends FormRequest
{
    /**
     * The 'laboratory-samples.reject' permission plus hospital scoping is
     * enforced by LaboratorySamplePolicy in the controller.
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
            'rejection_reason' => ['required', 'string', Rule::in(LaboratorySample::REJECTION_REASONS)],
            'rejection_notes' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('rejection_reason') === 'other')],
        ];
    }
}
