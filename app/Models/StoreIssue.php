<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StoreIssue extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'issue_number',
        'hospital_id',
        'from_store_id',
        'department_id',
        'department_stock_request_id',
        'issued_by',
        'issued_at',
        'received_by',
        'received_at',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DepartmentStockRequest::class, 'department_stock_request_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreIssueItem::class);
    }

    public function approvalHistories(): MorphMany
    {
        return $this->morphMany(ApprovalHistory::class, 'approvable');
    }
}
