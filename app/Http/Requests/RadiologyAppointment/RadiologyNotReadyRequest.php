<?php

namespace App\Http\Requests\RadiologyAppointment;

use App\Models\RadiologyAppointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RadiologyNotReadyRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(RadiologyAppointment::NOT_READY_REASONS)],
        ];
    }
}
