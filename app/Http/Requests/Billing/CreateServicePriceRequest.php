<?php

namespace App\Http\Requests\Billing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateServicePriceRequest extends FormRequest
{
    /**
     * The 'billing-pricing.create' permission plus hospital scoping is
     * enforced by ServicePricePolicy::createInto() in the controller,
     * against the validated hospital_id.
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
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
