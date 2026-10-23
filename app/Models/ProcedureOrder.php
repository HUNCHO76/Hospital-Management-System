<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A doctor-prescribed clinical procedure or surgical operation order.
 */
class ProcedureOrder extends Model
{
    use GeneratesBusinessNumber;

    public const NUMBER_PREFIX = 'PROC';

    public const TYPE_BEDSIDE = 'bedside_procedure';

    public const TYPE_MINOR_THEATRE = 'minor_theatre';

    public const TYPE_MAJOR_THEATRE = 'major_theatre';

    public const TYPE_ENDOSCOPY = 'endoscopy';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_BEDSIDE => 'Bedside Procedure',
        self::TYPE_MINOR_THEATRE => 'Minor Operating Theatre',
        self::TYPE_MAJOR_THEATRE => 'Major Operating Theatre',
        self::TYPE_ENDOSCOPY => 'Endoscopy / Diagnostic Procedure',
        self::TYPE_OTHER => 'Other Procedure',
    ];

    public const URGENCIES = [
        'elective' => 'Elective',
        'urgent' => 'Urgent',
        'emergency' => 'Emergency',
    ];

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ORDERED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_number',
        'admission_id',
        'patient_id',
        'clinical_encounter_id',
        'service_id',
        'procedure_name',
        'procedure_type',
        'urgency',
        'clinical_indication',
        'prescribed_by',
        'scheduled_at',
        'status',
        'cancelled_at',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'order_number');
            }
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    public function surgicalCase(): HasOne
    {
        return $this->hasOne(SurgicalCase::class);
    }

    public function bedsideEvents(): HasMany
    {
        return $this->hasMany(BedsideProcedureEvent::class)->orderByDesc('performed_at');
    }
}
