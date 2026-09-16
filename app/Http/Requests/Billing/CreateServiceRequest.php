<?php

namespace App\Http\Requests\Billing;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateServiceRequest extends FormRequest
{
    /**
     * The 'billing-services.create' permission is enforced by
     * ServicePolicy::create() in the controller.
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
            'code' => ['required', 'string', 'max:50', Rule::unique(Service::class, 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', Rule::in(Service::CATEGORIES)],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }
}
