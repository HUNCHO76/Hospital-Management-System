<?php

namespace App\Http\Requests\Inpatient;

use App\Models\MedicationAdministration;
use App\Models\PrescriptionOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordMedicationAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient.medications.administer')
            || $this->user()->can('inpatient.medications.hold')
            || $this->user()->can('inpatient.medications.record-refusal')
            || $this->user()->can('inpatient.medications.record-missed');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prescription_order_item_id' => ['required', 'integer', 'exists:prescription_order_items,id'],
            'clinical_encounter_id' => ['required', 'integer', 'exists:clinical_encounters,id'],
            'dispensing_item_id' => ['nullable', 'integer', 'exists:dispensing_items,id'],
            'status' => ['required', Rule::in(MedicationAdministration::STATUSES)],
            'administered_at' => ['required', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'dose' => ['required', 'string', 'max:100'],
            'dose_unit' => ['required', 'string', 'max:50'],
            'route' => ['required', Rule::in(PrescriptionOrderItem::ROUTES)],
            'is_prn' => ['boolean'],
            'reason' => [
                Rule::requiredIf(fn () => in_array($this->input('status'), [
                    MedicationAdministration::STATUS_HELD,
                    MedicationAdministration::STATUS_REFUSED,
                    MedicationAdministration::STATUS_MISSED,
                ], true)),
                'nullable',
                'string',
                'max:1000',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
