<?php

namespace App\Http\Requests\CarePlan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CorrectCarePlanItemRequest extends FormRequest
{
    /**
     * The 'treatment.correct' permission plus hospital/ownership scoping is
     * enforced by CarePlanItemPolicy in the controller. Prescription fields
     * are deliberately not correctable here — see
     * CarePlanService::correctItem()'s class docs.
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
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
