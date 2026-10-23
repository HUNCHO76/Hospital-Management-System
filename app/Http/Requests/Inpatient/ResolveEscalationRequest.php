<?php

namespace App\Http\Requests\Inpatient;

use Illuminate\Foundation\Http\FormRequest;

class ResolveEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-escalations.resolve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
