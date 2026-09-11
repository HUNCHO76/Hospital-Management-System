<?php

namespace App\Http\Requests\RadiologyReport;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitRadiologyReportRequest extends FormRequest
{
    /**
     * The 'radiology-results.submit' permission, hospital scoping, and
     * report-ownership are enforced by RadiologyReportPolicy in the
     * controller. Required-findings/impression completeness is enforced by
     * RadiologyReportService::submit() — it depends on the report's own
     * already-saved content, not on anything in this request body.
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
        return [];
    }
}
