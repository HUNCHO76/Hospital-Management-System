<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class RecordPacuRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('surgical.theatre-manage') || $this->user()->can('surgical.operate');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recovery_started_at' => ['nullable', 'date'],
            'recovery_ended_at' => ['required', 'date'],
            'aldrete_score' => ['required', 'integer', 'between:0,10'],
            'post_operative_orders' => ['required', 'string', 'max:3000'],
            'transferred_to_ward_at' => ['nullable', 'date'],
        ];
    }
}
