<?php

namespace App\Http\Requests\RadiologyReport;

use App\Models\RadiologyReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnRadiologyReportRequest extends FormRequest
{
    /**
     * The 'radiology-results.return' permission, hospital scoping, and
     * self-verification protection are enforced by RadiologyReportPolicy in
     * the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A reason is always mandatory (Part 11); comments are required only
     * when "other" is selected, mirroring
     * LaboratoryResult\ReturnForCorrectionRequest's identical pattern.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_reason' => ['required', 'string', Rule::in(RadiologyReport::RETURN_REASONS)],
            'return_comments' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('return_reason') === 'other')],
        ];
    }
}
