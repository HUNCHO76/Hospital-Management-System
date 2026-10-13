<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementItem extends Model
{
    use HasFactory;

    public const CATEGORY_MEDICINE = 'medicine';

    public const CATEGORY_CONSUMABLE = 'medical_consumable';

    public const CATEGORY_REAGENT = 'laboratory_reagent';

    public const CATEGORY_RADIOLOGY = 'radiology_supply';

    public const CATEGORY_EQUIPMENT = 'medical_equipment';

    public const CATEGORY_SURGICAL = 'surgical_theatre';

    public const CATEGORY_GENERAL = 'general_supply';

    public const CATEGORIES = [
        self::CATEGORY_MEDICINE,
        self::CATEGORY_CONSUMABLE,
        self::CATEGORY_REAGENT,
        self::CATEGORY_RADIOLOGY,
        self::CATEGORY_EQUIPMENT,
        self::CATEGORY_SURGICAL,
        self::CATEGORY_GENERAL,
    ];

    protected $fillable = [
        'code',
        'name',
        'category',
        'medication_id',
        'unit_of_measure',
        'specification',
        'reorder_level',
        'minimum_stock',
        'estimated_unit_cost',
        'requires_batch',
        'requires_expiry',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'integer',
            'minimum_stock' => 'integer',
            'estimated_unit_cost' => 'decimal:2',
            'requires_batch' => 'boolean',
            'requires_expiry' => 'boolean',
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
