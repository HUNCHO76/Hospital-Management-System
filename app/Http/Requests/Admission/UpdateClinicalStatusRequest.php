<?php

namespace App\Http\Requests\Admission;

use App\Models\Admission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient.clinical-status.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinical_status' => ['required', 'string', Rule::in(Admission::CLINICAL_STATUSES)],
        ];
    }
}
