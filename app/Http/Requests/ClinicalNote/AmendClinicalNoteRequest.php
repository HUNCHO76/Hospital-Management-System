<?php

namespace App\Http\Requests\ClinicalNote;

use App\Models\ClinicalNote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AmendClinicalNoteRequest extends FormRequest
{
    /**
     * The 'clinical-notes.amend' permission plus author/hospital scoping is
     * enforced by ClinicalNotePolicy::amend() in the controller.
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
            'note_type' => ['sometimes', 'string', Rule::in(ClinicalNote::TYPES)],
            'note_content' => ['sometimes', 'string', 'max:5000'],
            'amendment_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
