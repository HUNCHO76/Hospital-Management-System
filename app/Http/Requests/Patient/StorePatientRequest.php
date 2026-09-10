<?php

namespace App\Http\Requests\Patient;

use App\Models\Patient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePatientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'patients.create' permission is enforced by PatientPolicy via
     * authorizeResource() in the controller. Patients are a global identity
     * (no hospital_id on the patients table), so no hospital scoping applies.
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
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:255'],
            'passport_number' => ['nullable', 'string', 'max:255'],
            'other_identification' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', Rule::in(Patient::MARITAL_STATUSES)],
            'blood_group' => ['nullable', Rule::in(Patient::BLOOD_GROUPS)],
            'address' => ['nullable', 'string'],
            'region' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'ward' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([Patient::STATUS_ACTIVE, Patient::STATUS_INACTIVE, Patient::STATUS_DECEASED])],

            'confirm_duplicate' => ['boolean'],

            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.relationship' => ['required', 'string', 'max:255'],
            'contacts.*.phone' => ['required', 'string', 'max:50'],
            'contacts.*.alternative_phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.address' => ['nullable', 'string'],
            'contacts.*.is_primary' => ['boolean'],
        ];
    }

    /**
     * Extra validation that can't be expressed as simple rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $primaryCount = collect($this->input('contacts', []))
                ->filter(fn ($contact) => filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))
                ->count();

            if ($primaryCount > 1) {
                $validator->errors()->add('contacts', 'Only one contact can be marked as the primary contact.');
            }
        });
    }
}
