<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NHIF price/catalog synchronization run history (Phase 8, Step 68).
 */
class NhifPriceSyncLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'hospital_id',
        'facility_code',
        'sync_type',
        'type_name',
        'facility_level',
        'status',
        'total_records',
        'new_records',
        'updated_records',
        'skipped_records',
        'error_message',
        'started_at',
        'completed_at',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
