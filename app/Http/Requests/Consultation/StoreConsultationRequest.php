<?php

namespace App\Http\Requests\Consultation;

use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConsultationRequest extends FormRequest
{
    /**
     * The base 'consultation.create' permission is enforced by
     * ConsultationPolicy in the controller. Since no Consultation instance
     * exists yet at that point, the hospital-scoping restriction for
     * hospital-admins is enforced here instead, against the route-bound
     * encounter's own department — the same split StoreTriageRequest uses.
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
     * No body fields — starting a consultation only records who/when;
     * clinical content is captured afterward via UpdateConsultationRequest.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
