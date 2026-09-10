<?php

namespace App\Http\Requests\Practitioner;

use App\Models\Practitioner;
use App\Models\Staff;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePractitionerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'practitioners.create' permission is enforced by
     * PractitionerPolicy via authorizeResource() in the controller. Since no
     * Practitioner instance exists yet at that point, the hospital-scoping
     * restriction for hospital-admins is enforced here instead, against the
     * hospital of the staff member being selected.
     */
    public function authorize(): bool
    {
        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        $staffHospitalId = Staff::query()->find($this->input('staff_id'))?->hospital_id;

        return $staffHospitalId !== null && $this->user()->staff?->hospital_id === $staffHospitalId;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer', 'exists:staff,id', 'unique:practitioners,staff_id'],
            'practitioner_type' => ['required', Rule::in(Practitioner::TYPES)],
            'professional_registration_number' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in([Practitioner::STATUS_ACTIVE, Practitioner::STATUS_INACTIVE, Practitioner::STATUS_SUSPENDED])],
        ];
    }
}
