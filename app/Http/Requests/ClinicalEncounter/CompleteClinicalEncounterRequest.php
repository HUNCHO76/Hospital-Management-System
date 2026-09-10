<?php

namespace App\Http\Requests\ClinicalEncounter;

use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteClinicalEncounterRequest extends FormRequest
{
    /**
     * The 'clinical-encounters.complete' permission plus hospital/ownership
     * scoping is enforced by ClinicalEncounterPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both fields are optional — a simple encounter may be completed with no
     * declared outcome at all, in which case ClinicalEncounterService
     * defaults it to DISPOSITION_COMPLETED.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'disposition' => ['nullable', 'string', Rule::in(ClinicalEncounter::DISPOSITIONS)],
            'disposition_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
