<?php

namespace App\Http\Requests\Inpatient;

use App\Models\NursingHandover;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNursingHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.handover-create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shift' => ['required', 'string', Rule::in(NursingHandover::SHIFTS)],
            'handover_at' => ['required', 'date'],
            'situation_summary' => ['required', 'string', 'max:5000'],
            'pending_tasks' => ['nullable', 'string', 'max:5000'],
            'outstanding_doses' => ['nullable', 'array'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
