<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'received_date' => ['nullable', 'date'],
            'delivery_note_number' => ['nullable', 'string', 'max:100'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_delivered' => ['required', 'integer', 'min:1'],
            'items.*.quantity_accepted' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity_rejected' => ['nullable', 'integer', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.manufacture_date' => ['nullable', 'date'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.storage_location' => ['nullable', 'string', 'max:100'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
