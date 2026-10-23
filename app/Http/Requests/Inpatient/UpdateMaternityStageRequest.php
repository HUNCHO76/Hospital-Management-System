<?php

namespace App\Http\Requests\Inpatient;

use App\Models\MaternityProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaternityStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inpatient-nursing.view') || $this->user()->can('maternity.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'care_stage' => ['required', 'string', Rule::in(array_keys(MaternityProfile::CARE_STAGES))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
