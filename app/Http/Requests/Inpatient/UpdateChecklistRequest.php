<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('surgical.operate') || $this->user()->can('surgical.theatre-manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identity_confirmed' => ['boolean'],
            'site_marked' => ['boolean'],
            'consent_confirmed' => ['boolean'],
            'anesthesia_safety_check_completed' => ['boolean'],
            'pulse_oximeter_functioning' => ['boolean'],
            'allergy_screened' => ['boolean'],
            'difficult_airway_risk_assessed' => ['boolean'],
            'blood_loss_risk_assessed' => ['boolean'],
            'fasting_npo_hours_confirmed' => ['boolean'],
        ];
    }
}
