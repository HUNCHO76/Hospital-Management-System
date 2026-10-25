<?php

namespace App\Http\Requests\Inpatient;

use App\Models\MaternityProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaternityProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('maternity.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gravida' => ['required', 'integer', 'min:1', 'max:25'],
            'para' => ['required', 'integer', 'min:0', 'max:25'],
            'living_children' => ['nullable', 'integer', 'min:0', 'max:25'],
            'abortions' => ['nullable', 'integer', 'min:0', 'max:25'],
            'last_menstrual_period' => ['nullable', 'date', 'before_or_equal:today'],
            'estimated_date_of_delivery' => ['nullable', 'date'],
            'gestational_age_weeks' => ['nullable', 'numeric', 'between:10,45'],
            'presentation' => ['nullable', 'string', Rule::in(MaternityProfile::PRESENTATIONS)],
            'membrane_status' => ['nullable', 'string', Rule::in(MaternityProfile::MEMBRANE_STATUSES)],
            'membrane_ruptured_at' => ['nullable', 'date'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'rhesus' => ['nullable', 'string', 'max:10'],
            'hiv_status' => ['nullable', 'string', Rule::in(MaternityProfile::HIV_STATUSES)],
            'vdrl_rpr_status' => ['nullable', 'string', 'max:20'],
            'risk_factors' => ['nullable', 'array'],
            'care_stage' => ['nullable', 'string', Rule::in(array_keys(MaternityProfile::CARE_STAGES))],
            'care_pathway' => ['nullable', 'string', Rule::in(['outpatient', 'emergency', 'inpatient'])],
            'labour_stage' => ['nullable', 'string', Rule::in(MaternityProfile::LABOUR_STAGES)],
            'assigned_midwife_id' => ['nullable', 'integer', 'exists:users,id'],
            'primary_clinician_id' => ['nullable', 'integer', 'exists:users,id'],
            'clinical_exception_reason' => ['nullable', 'string', 'max:2000'],
            'is_emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
