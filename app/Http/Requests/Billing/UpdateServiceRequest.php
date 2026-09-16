<?php

namespace App\Http\Requests\Billing;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    /**
     * The 'billing-services.update' permission is enforced by
     * ServicePolicy::update() in the controller. `code` is accepted here
     * (sometimes-unique, ignoring this record) so an as-yet-unpriced
     * service's code can still be corrected — once a ServicePrice
     * references it, ServiceCatalogService::update() itself silently keeps
     * the existing code, mirroring MedicationRequest's own precedent.
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
        /** @var Service $service */
        $service = $this->route('service');

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique(Service::class, 'code')->ignore($service->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', Rule::in(Service::CATEGORIES)],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }
}
