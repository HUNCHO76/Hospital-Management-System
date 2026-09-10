<?php

namespace App\Http\Requests\CarePlan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CorrectCarePlanRequest extends FormRequest
{
    /**
     * The 'treatment.correct' permission plus hospital/ownership scoping is
     * enforced by CarePlanPolicy in the controller.
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
            'goals' => ['nullable', 'string', 'max:2000'],
            'clinical_instructions' => ['nullable', 'string', 'max:5000'],
            'monitoring_instructions' => ['nullable', 'string', 'max:5000'],
            'follow_up_instructions' => ['nullable', 'string', 'max:5000'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
