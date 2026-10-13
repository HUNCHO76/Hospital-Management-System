<?php

namespace App\Http\Requests\Stores;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentStockRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'priority' => ['nullable', 'string', 'in:normal,urgent,emergency'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:50'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
