<?php

namespace App\Http\Requests\LaboratoryResult;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.verify' permission, hospital scoping, and
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
            'verification_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
