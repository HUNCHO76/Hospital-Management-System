<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentStockRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_stock_request_id',
        'procurement_item_id',
        'item_name',
        'unit_of_measure',
        'quantity_requested',
        'quantity_approved',
        'quantity_issued',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'quantity_issued' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DepartmentStockRequest::class, 'department_stock_request_id');
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }
}
