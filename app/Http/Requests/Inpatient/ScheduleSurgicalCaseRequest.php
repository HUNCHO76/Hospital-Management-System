<?php

namespace App\Http\Requests\Inpatient;

use App\Models\SurgicalCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleSurgicalCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('surgical.theatre-manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'theatre_room' => ['required', 'string', 'max:100'],
            'lead_surgeon_id' => ['required', 'integer', 'exists:users,id'],
            'assistant_surgeon_id' => ['nullable', 'integer', 'exists:users,id'],
            'anesthetist_id' => ['nullable', 'integer', 'exists:users,id'],
            'scrub_nurse_id' => ['nullable', 'integer', 'exists:users,id'],
            'circulating_nurse_id' => ['nullable', 'integer', 'exists:users,id'],
            'anesthesia_type' => ['required', 'string', Rule::in(array_keys(SurgicalCase::ANESTHESIA_TYPES))],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
