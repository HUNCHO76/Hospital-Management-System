<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_invoice_id',
        'purchase_order_item_id',
        'goods_receipt_note_item_id',
        'procurement_item_id',
        'item_name',
        'quantity',
        'unit_price',
        'tax_amount',
        'total_price',
        'matched_po_qty',
        'matched_grn_qty',
        'matched_po_price',
        'has_discrepancy',
        'discrepancy_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'matched_po_qty' => 'integer',
            'matched_grn_qty' => 'integer',
            'matched_po_price' => 'decimal:2',
            'has_discrepancy' => 'boolean',
        ];
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function goodsReceiptNoteItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteItem::class);
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }
}
