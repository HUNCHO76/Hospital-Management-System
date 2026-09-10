<?php

namespace App\Http\Requests\CarePlan;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelCarePlanRequest extends FormRequest
{
    /**
     * The 'treatment.cancel' permission plus hospital/ownership scoping is
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
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
