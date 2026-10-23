<?php

namespace App\Http\Requests\Inpatient;

use App\Models\ProcedureOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProcedureOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('procedures.order');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'procedure_name' => ['required_without:service_id', 'nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'clinical_encounter_id' => ['nullable', 'integer', 'exists:clinical_encounters,id'],
            'procedure_type' => ['required', 'string', Rule::in(array_keys(ProcedureOrder::TYPES))],
            'urgency' => ['required', 'string', Rule::in(array_keys(ProcedureOrder::URGENCIES))],
            'clinical_indication' => ['required', 'string', 'max:2000'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
