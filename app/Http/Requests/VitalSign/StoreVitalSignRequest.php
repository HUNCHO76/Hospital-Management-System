<?php

namespace App\Http\Requests\VitalSign;

use App\Http\Requests\Concerns\ValidatesVitalSignMeasurements;
use App\Models\ClinicalEncounter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVitalSignRequest extends FormRequest
{
    use ValidatesVitalSignMeasurements;

    /**
     * The base 'vital-signs.create' permission is enforced by
     * VitalSignPolicy in the controller. Since no VitalSign instance exists
     * yet at that point, the hospital-scoping restriction for
     * hospital-admins is enforced here instead, against the route-bound
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
        return $this->measurementRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->withMeasurementValidator($validator);
    }
}
