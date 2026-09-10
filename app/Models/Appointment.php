<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating appointment numbers.
     */
    public const NUMBER_PREFIX = 'APT';

    /**
     * Status values recognized for an appointment, in their normal lifecycle
     * order. See TRANSITIONS below for which moves are actually allowed.
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    /**
     * Statuses that still occupy the practitioner's schedule and therefore
     * count toward a double-booking conflict. Cancelled/no-show/completed
     * appointments free up their time slot.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_PROGRESS,
    ];

    /**
     * The controlled set of appointment types recognized by the system.
     */
    public const TYPES = [
        'initial_consultation',
        'follow_up',
        'review',
        'procedure',
        'specialist_consultation',
        'other',
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * AppointmentService — never trust a status change supplied directly by
     * the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CONFIRMED => [self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CHECKED_IN => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'department_id',
        'practitioner_id',
        'appointment_type',
        'scheduled_start',
        'scheduled_end',
        'status',
        'reason',
        'notes',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $appointment): void {
            if (empty($appointment->appointment_number)) {
                $appointment->appointment_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'appointment_number');
            }
        });
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The patient this appointment is for.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The department this appointment is booked under.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The practitioner this appointment is booked with.
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * The reception visit created when this appointment was checked in at
     * the front desk, if any (Step 19).
     */
    public function receptionVisit(): HasOne
    {
        return $this->hasOne(ReceptionVisit::class);
    }
}
