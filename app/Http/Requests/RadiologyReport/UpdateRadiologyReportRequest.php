<?php

namespace App\Http\Requests\RadiologyReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRadiologyReportRequest extends FormRequest
{
    /**
     * The 'radiology-results.update' permission, hospital scoping, and
     * report-ownership are enforced by RadiologyReportPolicy in the
     * controller.
     */
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
            'technique' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:8000'],
            'impression' => ['nullable', 'string', 'max:4000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
