<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_SENT_TO_VENDOR = 'SENT_TO_VENDOR';

    public const STATUS_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';

    public const STATUS_FULLY_RECEIVED = 'FULLY_RECEIVED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'po_number',
        'hospital_id',
        'vendor_id',
        'receiving_store_id',
        'indent_id',
        'vendor_quotation_id',
        'order_date',
        'expected_delivery_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_amount',
        'grand_total',
        'status',
        'terms_conditions',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'cancellation_reason',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function receivingStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'receiving_store_id');
    }

    public function indent(): BelongsTo
    {
        return $this->belongsTo(Indent::class);
    }

    public function vendorQuotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceiptNotes(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class);
    }

    public function approvalHistories(): MorphMany
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }
}
