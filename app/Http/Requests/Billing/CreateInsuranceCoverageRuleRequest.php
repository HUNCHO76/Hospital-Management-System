<?php

namespace App\Http\Requests\Billing;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInsuranceCoverageRuleRequest extends FormRequest
{
    /**
     * The 'billing-insurance-rules.create' permission is enforced by
     * InsuranceCoverageRulePolicy::create() in the controller — this
     * resource has no hospital scoping to check against (see the policy's
     * own docblock).
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
            'insurance_scheme_id' => ['required', 'integer', 'exists:insurance_schemes,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'category' => ['nullable', 'string', Rule::in(Service::CATEGORIES)],
            'coverage_type' => ['required', 'string', Rule::in(['percentage', 'fixed_copay', 'full', 'not_covered'])],
            'coverage_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_copay_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'requires_preauthorization' => ['nullable', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
