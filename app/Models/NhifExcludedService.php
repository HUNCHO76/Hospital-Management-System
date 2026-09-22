<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NHIF excluded services per facility — synced from NHIF price list data (Phase 8, Step 68).
 */
class NhifExcludedService extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'hospital_id',
        'facility_code',
        'nhif_item_code',
        'nhif_item_name',
        'reason',
        'effective_date',
        'is_active',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_date' => 'date',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }
}
