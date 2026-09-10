<?php

namespace App\Http\Requests\ClinicalNote;

use App\Models\ClinicalNote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalNoteRequest extends FormRequest
{
    /**
     * The 'clinical-notes.update' permission plus author/hospital scoping is
     * enforced by ClinicalNotePolicy::update() in the controller.
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
            'note_type' => ['required', 'string', Rule::in(ClinicalNote::TYPES)],
            'note_content' => ['required', 'string', 'max:5000'],
        ];
    }
}
