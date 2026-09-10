<?php

namespace App\Http\Requests\Triage;

use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTriageRequest extends FormRequest
{
    /**
     * The base 'triage.create' permission is enforced by
     * TriageAssessmentPolicy in the controller. Since no TriageAssessment
     * instance exists yet at that point, the hospital-scoping restriction
     * for hospital-admins is enforced here instead, against the route-bound
     * encounter's own department.
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
        return [];
    }
}
