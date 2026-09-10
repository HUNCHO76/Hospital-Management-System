<?php

namespace App\Http\Requests\ClinicalEncounter;

use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\Department;
use App\Models\ReceptionVisit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClinicalEncounterRequest extends FormRequest
{
    /**
     * The base 'clinical-encounters.create' permission is enforced by
     * ClinicalEncounterPolicy in the controller. Since no ClinicalEncounter
     * instance exists yet at that point, the hospital-scoping restriction
     * for hospital-admins is enforced here instead, against the hospital of
     * the department the encounter is being opened under.
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
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'practitioner_id' => ['nullable', 'integer', Rule::exists('practitioners', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'reception_visit_id' => ['nullable', 'integer', 'exists:reception_visits,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'encounter_type' => ['required', 'string', Rule::in(ClinicalEncounter::TYPES)],
        ];
    }

    /**
     * Cross-field eligibility rules (spec §11): a reception visit or
     * appointment referenced on the encounter must actually belong to the
     * same patient, and a referenced reception visit must be in a state that
     * indicates the patient is actually ready for clinical service.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $patientId = $this->input('patient_id');

            $receptionVisitId = $this->input('reception_visit_id');

            if ($patientId && $receptionVisitId) {
                $visit = ReceptionVisit::query()->find($receptionVisitId);

                if ($visit && (int) $visit->patient_id !== (int) $patientId) {
                    $validator->errors()->add('reception_visit_id', 'The selected reception visit does not belong to this patient.');
                } elseif ($visit && ! in_array($visit->status, [ReceptionVisit::STATUS_READY_FOR_SERVICE, ReceptionVisit::STATUS_SENT_TO_SERVICE], true)) {
                    $validator->errors()->add('reception_visit_id', 'The selected reception visit is not yet ready for clinical service.');
                }
            }

            $appointmentId = $this->input('appointment_id');

            if ($patientId && $appointmentId) {
                $appointment = Appointment::query()->find($appointmentId);

                if ($appointment && (int) $appointment->patient_id !== (int) $patientId) {
                    $validator->errors()->add('appointment_id', 'The selected appointment does not belong to this patient.');
                }
            }
        });
    }
}
