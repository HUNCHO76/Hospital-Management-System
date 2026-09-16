<?php

namespace App\Http\Requests\Dispensing;

use App\Models\DispensingItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteDispensingRequest extends FormRequest
{
    /**
     * The 'pharmacy-dispensing.complete' permission plus hospital/ownership
     * scoping is enforced by PrescriptionOrderPolicy::completeDispensing()
     * in the controller. Quantity-vs-remaining validation happens in
     * DispensingService::complete() against freshly locked data — never
     * trust the client's own numbers beyond basic shape here.
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
            'items.*.prescription_order_item_id' => ['required', 'integer'],
            'items.*.dispensed_quantity' => ['required', 'integer', 'min:0'],
            'items.*.status' => ['sometimes', 'string', Rule::in(DispensingItem::STATUSES)],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
