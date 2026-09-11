<?php

namespace App\Http\Requests\RadiologyAppointment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleRadiologyAppointmentRequest extends FormRequest
{
    /**
     * The 'radiology-scheduling.reschedule' permission plus hospital
     * scoping is enforced by RadiologyAppointmentPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A reason is required (Part 18) — rescheduling must never silently
     * overwrite the original time without an explanation on record.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_start' => ['required', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
