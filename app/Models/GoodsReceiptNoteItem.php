<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receipt_note_id',
        'purchase_order_item_id',
        'procurement_item_id',
        'item_name',
        'unit_of_measure',
        'unit_cost',
        'quantity_ordered',
        'quantity_delivered',
        'quantity_accepted',
        'quantity_rejected',
        'quantity_short',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'storage_location',
        'stock_batch_id',
        'rejection_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'quantity_ordered' => 'integer',
            'quantity_delivered' => 'integer',
            'quantity_accepted' => 'integer',
            'quantity_rejected' => 'integer',
            'quantity_short' => 'integer',
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }
}
