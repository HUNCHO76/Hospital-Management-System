<?php

namespace App\Http\Requests\ClinicalNote;

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicalNoteRequest extends FormRequest
{
    /**
     * The base 'clinical-notes.create' permission is enforced by
     * ClinicalNotePolicy::create() in the controller. Since no ClinicalNote
     * instance exists yet at that point, the hospital-scoping restriction
     * for hospital-admins is enforced here instead, against the route-bound
     * encounter's own department — the same split StoreConsultationRequest/
     * StoreTriageRequest use.
     */
    public function authorize(): bool
    {
        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        /** @var ClinicalEncounter $encounter */
        $encounter = $this->route('clinicalEncounter');

        return $this->user()->staff?->hospital_id === $encounter->department->hospital_id;
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
