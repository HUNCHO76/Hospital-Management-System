<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NHIF service catalog item — synced from the NHIF OCS API (/api/Packages/GetItems).
 */
class NhifService extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_item_code',
        'nhif_item_name',
        'nhif_item_type_id',
        'nhif_item_type_name',
        'service_type_id',
        'sub_group',
        'strength',
        'dosage',
        'calculated_per_day',
        'is_restricted',
        'service_interval',
        'type_of_interval',
        'waiting_period',
        'type_of_period',
        'eligibility',
        'common_price',
        'percent_covered',
        'available_in_levels',
        'practitioner_qualifications',
        'is_active',
        'nhif_created_by',
        'nhif_date_created',
        'nhif_last_modified_by',
        'nhif_last_modified',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'service_type_id' => 'integer',
            'calculated_per_day' => 'boolean',
            'is_restricted' => 'boolean',
            'service_interval' => 'integer',
            'waiting_period' => 'integer',
            'common_price' => 'decimal:2',
            'percent_covered' => 'decimal:2',
            'is_active' => 'boolean',
            'nhif_date_created' => 'datetime',
            'nhif_last_modified' => 'datetime',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(NhifItemType::class, 'nhif_item_type_id', 'item_type_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(NhifServiceMapping::class, 'nhif_item_code', 'nhif_item_code');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeSelectable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeRestricted(Builder $query): void
    {
        $query->where('is_restricted', true);
    }

    public function scopeUnrestricted(Builder $query): void
    {
        $query->where('is_restricted', false);
    }

    public function scopeForItemType(Builder $query, int $itemTypeId): void
    {
        $query->where('nhif_item_type_id', $itemTypeId);
    }

    public function scopeForServiceType(Builder $query, int $serviceTypeId): void
    {
        $query->where('service_type_id', $serviceTypeId);
    }

    public function isSelectableForNewServices(): bool
    {
        return $this->is_active;
    }

    public function requiresPreAuthorization(): bool
    {
        return $this->is_restricted;
    }
}
