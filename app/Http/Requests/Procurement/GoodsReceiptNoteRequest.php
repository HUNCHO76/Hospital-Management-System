<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (isset($input['notes']) && ! isset($input['remarks'])) {
            $input['remarks'] = $input['notes'];
        }

        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $index => $item) {
                if (isset($item['item_description']) && ! isset($item['item_name'])) {
                    $item['item_name'] = $item['item_description'];
                }
                if (isset($item['quantity_received']) && ! isset($item['quantity_delivered'])) {
                    $item['quantity_delivered'] = (int) $item['quantity_received'];
                }
                if (! isset($item['quantity_accepted']) && isset($item['quantity_delivered'])) {
                    $item['quantity_accepted'] = (int) $item['quantity_delivered'];
                }
                $input['items'][$index] = $item;
            }
        }

        $this->replace($input);
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
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.item_name' => ['required_without:items.*.item_description', 'nullable', 'string', 'max:255'],
            'items.*.item_description' => ['nullable', 'string', 'max:255'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_delivered' => ['required_without:items.*.quantity_received', 'nullable', 'integer', 'min:1'],
            'items.*.quantity_received' => ['nullable', 'integer', 'min:1'],
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
