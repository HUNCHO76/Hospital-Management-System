<?php

namespace App\Http\Requests\PrescriptionOrder;

use App\Models\Medication;
use App\Models\PrescriptionOrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrescriptionOrderRequest extends FormRequest
{
    /**
     * The 'prescription-orders.update' permission plus hospital/ownership
     * scoping is enforced by PrescriptionOrderPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * items, when present, only ever adds new medications to the order —
     * removing one is cancelItem()'s job, never a plain edit here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clinical_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
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
