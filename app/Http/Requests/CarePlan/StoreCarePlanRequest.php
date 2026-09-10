<?php

namespace App\Http\Requests\CarePlan;

use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCarePlanRequest extends FormRequest
{
    /**
     * The base 'treatment.create' permission is enforced by
     * CarePlanPolicy::create() in the controller, which receives the
     * route-bound encounter as extra context. Since no CarePlan instance
     * exists yet at that point, the hospital-scoping restriction for
     * hospital-admins is enforced here instead, against the route-bound
     * encounter's own department — the same split StoreConsultationRequest
     * uses.
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
            'goals' => ['nullable', 'string', 'max:2000'],
            'clinical_instructions' => ['nullable', 'string', 'max:5000'],
            'monitoring_instructions' => ['nullable', 'string', 'max:5000'],
            'follow_up_instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
