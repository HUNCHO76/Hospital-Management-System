<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    public const TYPE_CENTRAL = 'central';

    public const TYPE_PHARMACY = 'pharmacy';

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_RADIOLOGY = 'radiology';

    public const TYPE_THEATRE = 'theatre';

    public const TYPE_WARD = 'ward';

    public const TYPE_SUB_STORE = 'sub_store';

    protected $fillable = [
        'hospital_id',
        'department_id',
        'code',
        'name',
        'type',
        'is_default',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }
}
