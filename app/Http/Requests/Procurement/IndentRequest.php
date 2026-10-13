<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class IndentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'target_store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'indent_date' => ['nullable', 'date'],
            'required_by_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'in:low,normal,urgent,emergency'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:50'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
            'items.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.purpose' => ['nullable', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:500'],
        ];
    }
}
