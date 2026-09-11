<?php

namespace App\Http\Requests\RadiologyAppointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RadiologyScreeningRequest extends FormRequest
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
            'flagged' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
