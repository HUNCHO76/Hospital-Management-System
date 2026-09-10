<?php

namespace App\Http\Requests\ClinicalNote;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelClinicalNoteRequest extends FormRequest
{
    /**
     * The 'clinical-notes.cancel' permission plus author/hospital scoping is
     * enforced by ClinicalNotePolicy::cancel() in the controller.
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
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
