<?php

namespace App\Http\Requests\Laboratory;

use App\Models\LaboratoryReferenceRange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryReferenceRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sex' => ['required', 'string', Rule::in(LaboratoryReferenceRange::SEXES)],
            'age_min_days' => ['nullable', 'integer', 'min:0'],
            'age_max_days' => ['nullable', 'integer', 'min:0', 'gte:age_min_days'],
            'low' => ['nullable', 'numeric'],
            'high' => ['nullable', 'numeric', 'gte:low'],
            'critical_low' => ['nullable', 'numeric'],
            'critical_high' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
