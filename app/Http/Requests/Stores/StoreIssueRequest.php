<?php

namespace App\Http\Requests\Stores;

use Illuminate\Foundation\Http\FormRequest;

class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_stock_request_id' => ['nullable', 'integer', 'exists:department_stock_requests,id'],
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.stock_batch_id' => ['nullable', 'integer', 'exists:stock_batches,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity_issued' => ['required', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
