<?php

namespace App\Http\Requests\LaboratoryResult;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseLaboratoryResultRequest extends FormRequest
{
    /**
     * The 'laboratory-results.release' permission plus hospital scoping is
     * enforced by LaboratoryResultPolicy in the controller.
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
            'release_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
