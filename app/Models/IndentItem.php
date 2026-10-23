<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'indent_id',
        'procurement_item_id',
        'item_name',
        'unit_of_measure',
        'quantity_requested',
        'quantity_approved',
        'quantity_ordered',
        'estimated_unit_cost',
        'estimated_total_cost',
        'purpose',
        'specification',
        'specifications',
        'justification',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'estimated_unit_cost' => 'decimal:2',
            'estimated_total_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->specification && ! $item->specifications) {
                $item->specifications = $item->specification;
            } elseif ($item->specifications && ! $item->specification) {
                $item->specification = $item->specifications;
            }

            if ($item->purpose && ! $item->justification) {
                $item->justification = $item->purpose;
            } elseif ($item->justification && ! $item->purpose) {
                $item->purpose = $item->justification;
            }
        });
    }

    public function indent(): BelongsTo
    {
        return $this->belongsTo(Indent::class);
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }
}
