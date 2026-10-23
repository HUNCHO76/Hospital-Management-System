<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A documented patient allergy or intolerance. Used by the nursing workspace
 * (display) and MedicationAdministrationService (matching an ordered medicine
 * to an active allergy before a dose is recorded as given). A record is never
 * deleted — it is resolved with a reason, so history stays intact.
 */
class PatientAllergy extends Model
{
    public const SEVERITY_MILD = 'mild';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SEVERE = 'severe';

    /** @var list<string> */
    public const SEVERITIES = [self::SEVERITY_MILD, self::SEVERITY_MODERATE, self::SEVERITY_SEVERE];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    protected $fillable = [
        'patient_id',
        'allergen',
        'medication_id',
        'severity',
        'reaction',
        'status',
        'recorded_by',
        'resolved_by',
        'resolved_at',
        'resolution_reason',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
