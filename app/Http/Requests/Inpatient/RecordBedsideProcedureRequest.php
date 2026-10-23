<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class RecordBedsideProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('procedures.order') || $this->user()->can('procedures.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'performed_at' => ['required', 'date'],
            'procedure_notes' => ['required', 'string', 'max:5000'],
            'patient_tolerance' => ['required', 'string', 'max:50'],
            'consumables_used' => ['nullable', 'string', 'max:2000'],
            'complications' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
