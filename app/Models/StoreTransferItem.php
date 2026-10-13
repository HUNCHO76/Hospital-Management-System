<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_transfer_id',
        'procurement_item_id',
        'from_stock_batch_id',
        'to_stock_batch_id',
        'item_name',
        'batch_number',
        'expiry_date',
        'quantity_transferred',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity_transferred' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StoreTransfer::class, 'store_transfer_id');
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }

    public function fromStockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'from_stock_batch_id');
    }

    public function toStockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'to_stock_batch_id');
    }
}
