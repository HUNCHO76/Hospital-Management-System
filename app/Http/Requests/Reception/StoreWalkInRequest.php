<?php

namespace App\Http\Requests\Reception;

use App\Models\Department;
use App\Models\ReceptionVisit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalkInRequest extends FormRequest
{
    /**
     * The base 'reception.create_walkin' permission is enforced by
     * ReceptionVisitPolicy in the controller. Since no ReceptionVisit
     * instance exists yet at that point, the hospital-scoping restriction
     * for hospital-admins is enforced here instead, against the hospital of
     * the department the walk-in is being sent to.
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
            'visit_type' => ['required', Rule::in(ReceptionVisit::TYPES)],
            'practitioner_id' => ['nullable', 'integer', Rule::exists('practitioners', 'id')->where(fn ($query) => $query->where('status', 'active'))],
        ];
    }
}
