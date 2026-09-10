<?php

namespace App\Http\Requests\LaboratorySample;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelLaboratorySampleRequest extends FormRequest
{
    /**
     * The 'laboratory-samples.cancel' permission plus hospital scoping is
     * enforced by LaboratorySamplePolicy in the controller.
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
