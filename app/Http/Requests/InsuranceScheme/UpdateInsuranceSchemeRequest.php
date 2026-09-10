<?php

namespace App\Http\Requests\InsuranceScheme;

use App\Models\InsuranceScheme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInsuranceSchemeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'insurance-schemes.update' permission is enforced by
     * InsuranceSchemePolicy via authorizeResource() in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var InsuranceScheme $scheme */
        $scheme = $this->route('scheme');

        return [
            'insurance_provider_id' => ['required', 'integer', 'exists:insurance_providers,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('insurance_schemes', 'code')
                    ->where('insurance_provider_id', $this->input('insurance_provider_id'))
                    ->ignore($scheme->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in([InsuranceScheme::STATUS_ACTIVE, InsuranceScheme::STATUS_INACTIVE])],
        ];
    }
}
