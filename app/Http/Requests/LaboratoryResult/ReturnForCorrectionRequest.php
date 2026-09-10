<?php

namespace App\Http\Requests\LaboratoryResult;

use App\Models\LaboratoryResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnForCorrectionRequest extends FormRequest
{
    /**
     * The 'laboratory-results.return' permission, hospital scoping, and
     * entry-vs-review role separation are enforced by LaboratoryResultPolicy
     * in the controller.
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
            'return_reason' => ['required', 'string', Rule::in(LaboratoryResult::RETURN_REASONS)],
            'return_notes' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('return_reason') === 'other')],
        ];
    }
}
