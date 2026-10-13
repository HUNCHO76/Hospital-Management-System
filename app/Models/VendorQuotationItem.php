<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorQuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_quotation_id',
        'procurement_item_id',
        'item_name',
        'quantity',
        'unit_price',
        'discount_rate',
        'tax_rate',
        'total_price',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_rate' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function vendorQuotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class);
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }
}
