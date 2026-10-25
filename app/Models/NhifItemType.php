<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NHIF Item Type (category) definition synced from OCS /api/Packages/GetItemTypes.
 */
class NhifItemType extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'item_type_id',
        'type_name',
        'alias',
        'item_group',
        'display_item',
        'nhif_date_created',
        'nhif_last_modified',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'item_type_id' => 'integer',
            'display_item' => 'boolean',
            'nhif_date_created' => 'datetime',
            'nhif_last_modified' => 'datetime',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(NhifService::class, 'nhif_item_type_id', 'item_type_id');
    }

    public function scopeDisplayed(Builder $query): void
    {
        $query->where('display_item', true);
    }
}
