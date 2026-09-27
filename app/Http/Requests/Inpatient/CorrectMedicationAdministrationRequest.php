<?php

namespace App\Http\Requests\Inpatient;

use App\Models\MedicationAdministration;
use App\Models\PrescriptionOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectMedicationAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient.medications.correct');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'correction_reason' => ['required', 'string', 'max:1000'],
            'status' => ['required', Rule::in(MedicationAdministration::STATUSES)],
            'administered_at' => ['required', 'date'],
            'dose' => ['required', 'string', 'max:100'],
            'dose_unit' => ['required', 'string', 'max:50'],
            'route' => ['required', Rule::in(PrescriptionOrderItem::ROUTES)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
