<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class GoodsReceiptNote extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_INSPECTION_PENDING = 'INSPECTION_PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_PARTIALLY_ACCEPTED = 'PARTIALLY_ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_POSTED = 'POSTED';

    protected $fillable = [
        'grn_number',
        'purchase_order_id',
        'hospital_id',
        'vendor_id',
        'store_id',
        'received_by',
        'received_date',
        'delivery_note_number',
        'invoice_reference',
        'status',
        'inspection_notes',
        'inspected_by',
        'inspection_date',
        'posted_by',
        'posted_at',
        'remarks',
        'attachment_path',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'inspection_date' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteItem::class);
    }

    public function approvalHistories(): MorphMany
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }
}
