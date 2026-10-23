<?php

namespace App\Http\Requests\RadiologyOrder;

use App\Models\RadiologyOrder;
use App\Models\RadiologyProcedure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRadiologyOrderRequest extends FormRequest
{
    /**
     * The 'radiology-orders.create' permission plus hospital/ownership
     * scoping is enforced by RadiologyOrderPolicy::create() in the
     * controller, which receives the route-bound encounter as extra
     * context.
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
            'procedure_ids' => ['required', 'array', 'min:1'],
            'procedure_ids.*' => [
                'distinct',
                'integer',
                Rule::exists(RadiologyProcedure::class, 'id')->where('status', RadiologyProcedure::STATUS_ACTIVE),
            ],
            'priority' => ['nullable', 'string', Rule::in(RadiologyOrder::PRIORITIES)],
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'send_preapproval' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'procedure_ids.*.distinct' => 'Duplicate procedures cannot be added to the same radiology order.',
        ];
    }
}
