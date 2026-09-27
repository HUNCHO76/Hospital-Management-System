<?php

namespace App\Models;

use Database\Factories\DiagnosisMedicationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A safety rule linking a diagnosis code to a medication (Phase 3/6, spec §12).
 * MedicationSafetyService queries these at prescription-order creation time and
 * requires the prescriber to supply a justification whenever a match is found.
 */
class DiagnosisMedicationRule extends Model
{
    /** @use HasFactory<DiagnosisMedicationRuleFactory> */
    use HasFactory;

    public const RULE_TYPE_CONTRAINDICATION = 'contraindication';

    public const RULE_TYPE_CAUTION = 'caution';

    public const RULE_TYPES = [
        self::RULE_TYPE_CONTRAINDICATION,
        self::RULE_TYPE_CAUTION,
    ];

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const SEVERITIES = [
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
    ];

    /** @var list<string> */
    protected $fillable = [
        'diagnosis_code_id',
        'medication_id',
        'rule_type',
        'severity',
        'description',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function diagnosisCode(): BelongsTo
    {
        return $this->belongsTo(DiagnosisCode::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
