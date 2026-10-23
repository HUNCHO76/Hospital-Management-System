<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dressing / wound-care event under an admission. Each repeat dressing is
 * its own row; a mistake is corrected by a new row pointing at the original
 * through corrects_id (the same amendment-chain pattern as MedicationAdministration
 * and ClinicalNote), never by overwriting it.
 */
class WoundCareEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'patient_id',
        'performed_at',
        'site',
        'indication',
        'assessment',
        'appearance',
        'dressing_performed',
        'materials_used',
        'pain_score',
        'patient_response',
        'complications',
        'concerning_findings',
        'next_dressing_due_at',
        'follow_up_instructions',
        'recorded_by',
        'corrects_id',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'next_dressing_due_at' => 'datetime',
            'concerning_findings' => 'boolean',
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

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }
}
