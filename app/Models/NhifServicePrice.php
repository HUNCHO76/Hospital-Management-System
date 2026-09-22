<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NHIF allowable tariff for a service mapping, effective-dated (Phase 8, Step 68).
 */
class NhifServicePrice extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_service_mapping_id',
        'allowable_amount',
        'currency',
        'effective_from',
        'effective_to',
        'status',
        'sync_reference',
        'raw_data',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allowable_amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'raw_data' => 'array',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(NhifServiceMapping::class, 'nhif_service_mapping_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
