<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    /**
     * The 'appointments.reschedule' permission plus hospital scoping is
     * enforced by AppointmentPolicy in the controller.
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
            'scheduled_start' => ['required', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ];
    }
}
