<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class StoreEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.escalate') || $this->user()->can('inpatient-nursing.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'notified_practitioner_id' => ['nullable', 'integer', 'exists:practitioners,id'],
            'flags' => ['nullable', 'array'],
            'vital_sign_id' => ['nullable', 'integer', 'exists:vital_signs,id'],
        ];
    }
}
