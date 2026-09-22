<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NHIF service catalog item — synced from the NHIF API (Phase 8, Step 68).
 */
class NhifService extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_item_code',
        'nhif_item_name',
        'nhif_item_type_id',
        'nhif_item_type_name',
        'is_active',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(NhifServiceMapping::class, 'nhif_item_code', 'nhif_item_code');
    }
}
