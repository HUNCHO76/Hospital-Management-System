<?php

namespace App\Http\Requests\Practitioner;

use App\Models\Practitioner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePractitionerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'practitioners.update' permission and hospital-admin scoping
     * against the practitioner's staff hospital are enforced by
     * PractitionerPolicy via authorizeResource() in the controller. A
     * practitioner's staff_id is not editable, so no further hospital
     * re-scoping is needed here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Practitioner $practitioner */
        $practitioner = $this->route('practitioner');

        return [
            'practitioner_type' => ['required', Rule::in(Practitioner::TYPES)],
            'professional_registration_number' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in([Practitioner::STATUS_ACTIVE, Practitioner::STATUS_INACTIVE, Practitioner::STATUS_SUSPENDED])],
            'nhif_practitioner_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('practitioners', 'nhif_practitioner_no')->ignore($practitioner->id),
            ],
            'consultation_service_id' => ['nullable', 'integer', 'exists:services,id'],
        ];
    }
}
