<?php

namespace App\Http\Requests\Triage;

use App\Models\TriageAssessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTriageRequest extends FormRequest
{
    /**
     * The 'triage.update' permission plus hospital scoping is enforced by
     * TriageAssessmentPolicy in the controller.
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
            'triage_category' => ['nullable', 'string', Rule::in(TriageAssessment::CATEGORIES)],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
            'pain_score' => ['nullable', 'integer', 'min:'.config('vitals.ranges.pain_score.min'), 'max:'.config('vitals.ranges.pain_score.max')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
