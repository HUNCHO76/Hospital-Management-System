<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nursing shift-handover entry for an admitted patient. The outstanding
 * doses are snapshotted from the MAR at handover time so the entry remains an
 * accurate record even after those doses are later given. Corrections are new
 * rows (corrects_id), never edits.
 */
class NursingHandover extends Model
{
    public const SHIFTS = ['morning', 'afternoon', 'night'];

    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'patient_id',
        'shift',
        'handover_at',
        'situation_summary',
        'pending_tasks',
        'outstanding_doses',
        'recorded_by',
        'received_by',
        'corrects_id',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'handover_at' => 'datetime',
            'outstanding_doses' => 'array',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }
}
