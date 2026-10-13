<?php

namespace App\Http\Requests\Stores;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['required', 'integer', 'exists:stores,id', 'different:from_store_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.from_stock_batch_id' => ['required', 'integer', 'exists:stock_batches,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity_transferred' => ['required', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
