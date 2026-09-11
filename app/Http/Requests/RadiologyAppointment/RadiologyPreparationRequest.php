<?php

namespace App\Http\Requests\RadiologyAppointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RadiologyPreparationRequest extends FormRequest
{
    /**
     * The 'radiology-reception.manage' permission plus hospital scoping is
     * enforced by RadiologyAppointmentPolicy in the controller.
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
            'instructions_given' => ['sometimes', 'boolean'],
            'completed' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
