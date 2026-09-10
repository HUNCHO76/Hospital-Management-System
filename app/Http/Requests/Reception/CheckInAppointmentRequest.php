<?php

namespace App\Http\Requests\Reception;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckInAppointmentRequest extends FormRequest
{
    /**
     * The base 'reception.checkin' permission is enforced by
     * ReceptionVisitPolicy in the controller. The hospital-scoping
     * restriction for hospital-admins is checked against the appointment's
     * own department (route-bound), since that's the record actually being
     * acted on here.
     */
    public function authorize(): bool
    {
        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        /** @var Appointment $appointment */
        $appointment = $this->route('appointment');

        return $this->user()->staff?->hospital_id === $appointment->department->hospital_id;
    }

    /**
     * department_id is optional — it defaults to the appointment's own
     * department in the controller. It's only accepted here in case
     * reception ever needs to redirect the visit to a different department
     * than originally booked.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('status', 'active'))],
        ];
    }
}
