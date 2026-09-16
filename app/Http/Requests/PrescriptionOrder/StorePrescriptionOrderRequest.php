<?php

namespace App\Http\Requests\PrescriptionOrder;

use App\Models\Medication;
use App\Models\PrescriptionOrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrescriptionOrderRequest extends FormRequest
{
    /**
     * The 'prescription-orders.create' permission plus hospital/ownership
     * scoping is enforced by PrescriptionOrderPolicy::create() in the
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.medication_id' => [
                'distinct',
                'integer',
                Rule::exists(Medication::class, 'id')->where('status', Medication::STATUS_ACTIVE),
            ],
            'items.*.dose' => ['required', 'string', 'max:50'],
            'items.*.dose_unit' => ['nullable', 'string', 'max:30'],
            'items.*.frequency' => ['required', 'string', Rule::in(PrescriptionOrderItem::FREQUENCIES)],
            'items.*.route' => ['required', 'string', Rule::in(PrescriptionOrderItem::ROUTES)],
            'items.*.duration' => ['nullable', 'integer', 'min:1'],
            'items.*.duration_unit' => ['nullable', 'string', Rule::in(PrescriptionOrderItem::DURATION_UNITS)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.quantity_unit' => ['nullable', 'string', 'max:30'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'clinical_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.medication_id.distinct' => 'Duplicate medications cannot be added to the same prescription.',
        ];
    }
}
