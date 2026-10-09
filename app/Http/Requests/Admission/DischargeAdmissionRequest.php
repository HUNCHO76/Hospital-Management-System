<?php

namespace App\Http\Requests\Admission;

use App\Models\Admission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DischargeAdmissionRequest extends FormRequest
{
    /**
     * The 'admission.discharge' permission and hospital scoping are
     * enforced by an explicit AdmissionPolicy::discharge() check in the
     * controller before this request's rules() ever run.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'discharge_type' => ['required', Rule::in(Admission::DISCHARGE_TYPES)],
            'discharge_reason' => ['required', 'string', 'max:2000'],
            'diagnosis_at_discharge' => ['nullable', 'string', 'max:2000'],
            'referred_to_facility_code' => ['nullable', 'required_if:discharge_type,transferred_out', 'string', 'max:60'],
        ];
    }
}
