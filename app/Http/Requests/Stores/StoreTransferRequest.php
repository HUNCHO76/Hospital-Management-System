<?php

namespace App\Http\Requests\Stores;

use App\Models\StockBatch;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mergeData = [];

        if (! $this->filled('notes') && $this->filled('reason')) {
            $mergeData['notes'] = $this->input('reason');
        }

        if (is_array($this->input('items'))) {
            $items = $this->input('items');
            $batchIds = collect($items)->pluck('from_stock_batch_id')->filter()->all();
            $batches = StockBatch::with(['stockItem.procurementItem'])->whereIn('id', $batchIds)->get()->keyBy('id');

            foreach ($items as $index => $item) {
                if (! isset($item['quantity_transferred']) && isset($item['quantity'])) {
                    $item['quantity_transferred'] = $item['quantity'];
                }

                if (! isset($item['remarks']) && isset($item['notes'])) {
                    $item['remarks'] = $item['notes'];
                }

                if (empty($item['item_name'])) {
                    $batch = $batches->get($item['from_stock_batch_id'] ?? null);
                    $item['item_name'] = $batch?->stockItem?->procurementItem?->name
                        ?? $batch?->stockItem?->name
                        ?? ($item['batch_label'] ?? 'Stock Item');
                }

                if (empty($item['procurement_item_id'])) {
                    $batch = $batches->get($item['from_stock_batch_id'] ?? null);
                    $item['procurement_item_id'] = $batch?->stockItem?->procurement_item_id
                        ?? $batch?->stockItem?->procurementItem?->id;
                }

                $items[$index] = $item;
            }

            $mergeData['items'] = $items;
        }

        if (! empty($mergeData)) {
            $this->merge($mergeData);
        }
    }

    public function rules(): array
    {
        return [
            'from_store_id' => ['required', 'integer', 'exists:stores,id'],
            'to_store_id' => ['required', 'integer', 'exists:stores,id', 'different:from_store_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.procurement_item_id' => ['required', 'integer', 'exists:procurement_items,id'],
            'items.*.from_stock_batch_id' => ['required', 'integer', 'exists:stock_batches,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.quantity_transferred' => ['required', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
