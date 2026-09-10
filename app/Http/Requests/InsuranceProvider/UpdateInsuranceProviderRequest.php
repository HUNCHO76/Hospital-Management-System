<?php

namespace App\Http\Requests\InsuranceProvider;

use App\Models\InsuranceProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInsuranceProviderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The base 'insurance-providers.update' permission is enforced by
     * InsuranceProviderPolicy via authorizeResource() in the controller.
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
        /** @var InsuranceProvider $provider */
        $provider = $this->route('provider');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('insurance_providers', 'code')->ignore($provider->id)],
            'registration_number' => [
                'nullable', 'string', 'max:255',
                Rule::unique('insurance_providers', 'registration_number')->ignore($provider->id),
            ],
            'type' => ['required', Rule::in(InsuranceProvider::TYPES)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'api_enabled' => ['boolean'],
            'status' => ['required', Rule::in([InsuranceProvider::STATUS_ACTIVE, InsuranceProvider::STATUS_INACTIVE])],
        ];
    }
}
