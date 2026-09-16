<?php

namespace App\Http\Requests\Stock;

use App\Models\StockTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    /**
     * The 'pharmacy-stock.adjust' permission plus hospital scoping is
     * enforced by StockItemPolicy::adjust() in the controller. Quantity-vs-
     * balance validation happens in StockService::adjust() against freshly
     * locked data.
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
            'type' => ['required', 'string', Rule::in([
                StockTransaction::TYPE_ADJUSTMENT_IN,
                StockTransaction::TYPE_ADJUSTMENT_OUT,
                StockTransaction::TYPE_EXPIRED,
                StockTransaction::TYPE_DAMAGED,
            ])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
