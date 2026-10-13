<?php

namespace App\Http\Requests\Procurement;

use App\Models\ProcurementItem;
use Illuminate\Foundation\Http\FormRequest;

class ProcurementItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $itemId = $this->route('procurement_item')?->id ?? $this->route('procurement_item');

        return [
            'code' => ['required', 'string', 'max:50', 'unique:procurement_items,code,'.$itemId],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', ProcurementItem::CATEGORIES)],
            'medication_id' => ['nullable', 'integer', 'exists:medications,id'],
            'unit_of_measure' => ['required', 'string', 'max:50'],
            'specification' => ['nullable', 'string', 'max:1000'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'requires_batch' => ['nullable', 'boolean'],
            'requires_expiry' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
