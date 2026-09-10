<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Practitioner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * The base 'appointments.create' permission is enforced by
     * AppointmentPolicy via authorizeResource() in the controller. Since no
     * Appointment instance exists yet at that point, the hospital-scoping
     * restriction for hospital-admins is enforced here instead, against the
     * hospital of the department being booked under.
     */
    public function authorize(): bool
    {
        if (! $this->user()->hasRole('hospital-admin')) {
            return true;
        }

        $departmentHospitalId = Department::query()->find($this->input('department_id'))?->hospital_id;

        return $departmentHospitalId !== null && $this->user()->staff?->hospital_id === $departmentHospitalId;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'practitioner_id' => ['required', 'integer', Rule::exists('practitioners', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'appointment_type' => ['required', Rule::in(Appointment::TYPES)],
            'scheduled_start' => ['required', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * An appointment cannot span two hospitals — the practitioner must
     * belong to the same hospital as the department it's booked under.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $departmentId = $this->input('department_id');
            $practitionerId = $this->input('practitioner_id');

            if (! $departmentId || ! $practitionerId) {
                return;
            }

            $department = Department::query()->find($departmentId);
            $practitioner = Practitioner::query()->with('staff')->find($practitionerId);

            if ($department && $practitioner?->staff && $department->hospital_id !== $practitioner->staff->hospital_id) {
                $validator->errors()->add('practitioner_id', 'The selected practitioner does not belong to the selected department\'s hospital.');
            }
        });
    }
}
