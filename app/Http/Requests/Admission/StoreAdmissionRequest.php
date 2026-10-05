<?php

namespace App\Http\Requests\Admission;

use App\Models\Admission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionRequest extends FormRequest
{
    /**
     * No Admission instance exists yet at this point — hospital-scoping is
     * enforced via AdmissionPolicy::createInto() against the submitted
     * hospital_id, mirroring StoreWalkInRequest's own precedent.
     */
    public function authorize(): bool
    {
        return $this->user()->can('createInto', [Admission::class, (int) $this->input('hospital_id')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'clinical_encounter_id' => ['nullable', 'integer', 'exists:clinical_encounters,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'patient_insurance_id' => ['nullable', 'integer', 'exists:patient_insurances,id'],
            'practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'admission_type' => ['nullable', Rule::in(Admission::ADMISSION_TYPES)],
            'ward_type' => ['nullable', 'string', 'max:100'],
            'room_type' => ['nullable', 'string', 'max:100'],
            'diagnosis_at_admission' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'nhif_authorization_no' => ['nullable', 'string', 'max:50'],
            'nhif_ward_type_id' => ['nullable', 'integer'],
            'nhif_room_type_id' => ['nullable', 'integer'],
            'nhif_admission_type_id' => ['nullable', 'integer'],
        ];
    }
}
