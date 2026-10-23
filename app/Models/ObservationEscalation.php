<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A documented request for clinician review of an inpatient observation.
 * An escalation is a communication/review record — it is NOT a diagnosis and
 * carries no clinical conclusion. It is raised automatically when an
 * observation falls in a configured "critical" band (config/vitals.php), or
 * manually by a nurse, and is closed by an authorized clinician.
 */
class ObservationEscalation extends Model
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'patient_id',
        'vital_sign_id',
        'source',
        'reason',
        'flags',
        'raised_by',
        'raised_at',
        'notified_practitioner_id',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function vitalSign(): BelongsTo
    {
        return $this->belongsTo(VitalSign::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function notifiedPractitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'notified_practitioner_id');
    }
}
