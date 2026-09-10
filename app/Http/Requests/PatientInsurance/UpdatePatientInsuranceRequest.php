<?php

namespace App\Http\Requests\PatientInsurance;

use App\Models\PatientInsurance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientInsuranceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'patient-insurance.update' permission is enforced by
     * PatientInsurancePolicy via authorizeResource() in the controller.
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
            'insurance_provider_id' => ['required', 'integer', 'exists:insurance_providers,id'],
            'insurance_scheme_id' => [
                'nullable',
                'integer',
                Rule::exists('insurance_schemes', 'id')->where(fn ($query) => $query->where('insurance_provider_id', $this->input('insurance_provider_id'))),
            ],
            'membership_number' => ['required', 'string', 'max:255'],
            'policy_number' => ['nullable', 'string', 'max:255'],
            'card_number' => ['nullable', 'string', 'max:255'],
            'principal_member_name' => ['nullable', 'string', 'max:255'],
            'relationship' => ['nullable', Rule::in(PatientInsurance::RELATIONSHIPS)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_primary' => ['boolean'],
            'status' => ['required', Rule::in([
                PatientInsurance::STATUS_ACTIVE,
                PatientInsurance::STATUS_INACTIVE,
                PatientInsurance::STATUS_EXPIRED,
                PatientInsurance::STATUS_SUSPENDED,
            ])],
            'verification_status' => ['required', Rule::in(PatientInsurance::VERIFICATION_STATUSES)],
        ];
    }
}
