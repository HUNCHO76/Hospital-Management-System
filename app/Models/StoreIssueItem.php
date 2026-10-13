<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreIssueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_issue_id',
        'procurement_item_id',
        'stock_batch_id',
        'item_name',
        'batch_number',
        'expiry_date',
        'quantity_issued',
        'unit_cost',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity_issued' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(StoreIssue::class, 'store_issue_id');
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
