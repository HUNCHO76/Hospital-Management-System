<?php

namespace App\Http\Requests\Patient;

use App\Models\NhifMemberVerification;
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

            // Payment classification — required at registration.
            'payment_type' => ['required', Rule::in(['cash', 'insurance'])],

            // NHIF insurance fields — required only when payment_type is 'insurance'.
            'nhif_provider_id' => ['required_if:payment_type,insurance', 'nullable', Rule::exists('insurance_providers', 'id')->where('api_enabled', true)],
            'nhif_membership_number' => ['required_if:payment_type,insurance', 'nullable', 'string', 'max:50'],
            'nhif_card_number' => ['nullable', 'string', 'max:50'],
            'nhif_identifier_type' => ['required_if:payment_type,insurance', 'nullable', Rule::in([NhifMemberVerification::TYPE_NIN, NhifMemberVerification::TYPE_CARD_NUMBER])],
            'nhif_scheme_id' => ['nullable', Rule::exists('insurance_schemes', 'id')],

            // NHIF VerifyCard fields — required only when payment_type is 'insurance'.
            'nhif_visit_type_id' => ['nullable', 'integer'],
            'nhif_verifier_id' => ['nullable', 'string', 'max:50'],
            'nhif_card_type_id' => ['nullable', 'string', 'max:50'],
            'nhif_remarks' => ['nullable', 'string', 'max:500'],
            'nhif_referral_no' => ['nullable', 'string', 'max:100'],
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

            // When using card_number as the NHIF identifier, the card number field is required.
            if ($this->input('payment_type') === 'insurance'
                && $this->input('nhif_identifier_type') === NhifMemberVerification::TYPE_CARD_NUMBER
                && blank($this->input('nhif_card_number'))
            ) {
                $validator->errors()->add('nhif_card_number', 'Card number is required when verifying by card number.');
            }

            // When using NIN as the identifier, the patient must have a national_id.
            if ($this->input('payment_type') === 'insurance'
                && $this->input('nhif_identifier_type') === NhifMemberVerification::TYPE_NIN
                && blank($this->input('national_id'))
            ) {
                $validator->errors()->add('national_id', 'National ID is required when verifying NHIF membership by NIN.');
            }
        });
    }
}
