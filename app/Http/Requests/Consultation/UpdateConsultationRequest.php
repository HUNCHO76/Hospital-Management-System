<?php

namespace App\Http\Requests\Consultation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConsultationRequest extends FormRequest
{
    /**
     * The 'consultation.update' permission plus hospital/ownership scoping
     * is enforced by ConsultationPolicy in the controller.
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
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'history_presenting_illness' => ['nullable', 'string', 'max:5000'],
            'relevant_medical_history' => ['nullable', 'string', 'max:5000'],
            'relevant_surgical_history' => ['nullable', 'string', 'max:5000'],
            'relevant_social_history' => ['nullable', 'string', 'max:5000'],
            'review_of_systems' => ['nullable', 'string', 'max:5000'],
            'physical_examination' => ['nullable', 'string', 'max:5000'],
            'assessment_summary' => ['nullable', 'string', 'max:5000'],
            'clinical_notes' => ['nullable', 'string', 'max:5000'],
            'plan_summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
