<?php

namespace App\Http\Requests\Stock;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockRequest extends FormRequest
{
    /**
     * The 'pharmacy-stock.receive' permission plus hospital scoping is
     * enforced by StockItemPolicy::receiveInto() in the controller, against
     * the validated hospital_id — never trust a client-supplied hospital_id
     * beyond basic shape here (spec §31/§40).
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
            'hospital_id' => ['required', 'integer', 'exists:hospitals,id'],
            'medication_id' => ['required', 'integer', 'exists:medications,id'],
            'batch_number' => ['required', 'string', 'max:100'],
            'manufacture_date' => ['nullable', 'date', 'before_or_equal:expiry_date'],
            'expiry_date' => ['required', 'date', 'after:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'maximum_level' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
