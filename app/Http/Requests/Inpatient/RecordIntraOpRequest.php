<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class RecordIntraOpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('surgical.operate');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['required', 'date'],
            'pre_operative_diagnosis' => ['nullable', 'string', 'max:255'],
            'post_operative_diagnosis' => ['required', 'string', 'max:255'],
            'surgical_technique' => ['required', 'string', 'max:5000'],
            'intraoperative_findings' => ['nullable', 'string', 'max:5000'],
            'implants_used' => ['nullable', 'string', 'max:2000'],
            'drains_placed' => ['nullable', 'string', 'max:1000'],
            'swab_instrument_count_correct' => ['boolean'],
            'specimens_sent_to_lab' => ['nullable', 'string', 'max:2000'],
            'estimated_blood_loss_ml' => ['required', 'integer', 'min:0', 'max:10000'],
            'complications' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
