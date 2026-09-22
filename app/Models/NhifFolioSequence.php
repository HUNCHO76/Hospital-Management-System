<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NHIF folio number sequence per hospital/year/month (Phase 8, Steps 72–73).
 */
class NhifFolioSequence extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'hospital_id',
        'folio_year',
        'folio_month',
        'last_number',
    ];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * Atomically increment and return the next folio number for the given hospital and period.
     */
    public static function nextNumber(int $hospitalId, int $year, int $month): int
    {
        $sequence = static::lockForUpdate()->firstOrCreate(
            ['hospital_id' => $hospitalId, 'folio_year' => $year, 'folio_month' => $month],
            ['last_number' => 0],
        );

        $sequence->increment('last_number');
        $sequence->refresh();

        return $sequence->last_number;
    }
}
