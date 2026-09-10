<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    /**
     * The 'appointments.update' permission plus hospital scoping is enforced
     * by AppointmentPolicy via authorizeResource() in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Non-scheduling fields only — use RescheduleAppointmentRequest to
     * change the appointment's time.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_type' => ['required', Rule::in(Appointment::TYPES)],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
