<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\ReceptionVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReceptionVisit extends Model
{
    /** @use HasFactory<ReceptionVisitFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating visit numbers.
     */
    public const NUMBER_PREFIX = 'RV';

    /**
     * Status values recognized for a reception visit.
     *
     * There is no separate "arrived" state: both the walk-in and
     * appointment-check-in flows create the visit already at checked_in —
     * nothing in this app ever produces a visit that has arrived but not
     * yet been checked in, so that state was deliberately not modeled.
     */
    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_READY_FOR_SERVICE = 'ready_for_service';

    public const STATUS_SENT_TO_SERVICE = 'sent_to_service';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that count as an "active" visit — the set that blocks a
     * patient from having a second concurrent visit created.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_CHECKED_IN,
        self::STATUS_WAITING,
        self::STATUS_READY_FOR_SERVICE,
        self::STATUS_SENT_TO_SERVICE,
    ];

    /**
     * The controlled set of visit types recognized by the system.
     */
    public const TYPES = ['appointment', 'walk_in', 'follow_up', 'other'];

    /**
     * Dedicated visit purposes for Maternity Outpatient visits.
     */
    public const PURPOSE_ANC = 'antenatal_care';

    public const PURPOSE_MATERNITY_ASSESSMENT = 'maternity_assessment';

    public const PURPOSE_LABOUR_ASSESSMENT = 'labour_assessment';

    public const PURPOSE_POSTNATAL_CARE = 'postnatal_care';

    public const VISIT_PURPOSES = [
        self::PURPOSE_ANC => 'Antenatal Care (ANC)',
        self::PURPOSE_MATERNITY_ASSESSMENT => 'Maternity Assessment',
        self::PURPOSE_LABOUR_ASSESSMENT => 'Labour Assessment',
        self::PURPOSE_POSTNATAL_CARE => 'Postnatal Care',
    ];

    /**
     * Maternity queue statuses — separate from inpatient admission statuses.
     */
    public const MATERNITY_STATUS_WAITING = 'waiting_for_assessment';

    public const MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS = 'assessment_in_progress';

    public const MATERNITY_STATUS_AWAITING_CLINICIAN = 'awaiting_clinician';

    public const MATERNITY_STATUS_CONSULTATION_IN_PROGRESS = 'consultation_in_progress';

    public const MATERNITY_STATUS_AWAITING_INVESTIGATION = 'awaiting_investigation';

    public const MATERNITY_STATUS_TREATMENT_IN_PROGRESS = 'treatment_in_progress';

    public const MATERNITY_STATUS_FOLLOW_UP_REQUIRED = 'follow_up_required';

    public const MATERNITY_STATUS_COMPLETED = 'completed';

    public const MATERNITY_STATUS_ADMISSION_RECOMMENDED = 'admission_recommended';

    public const MATERNITY_STATUS_ADMITTED = 'admitted';

    public const MATERNITY_STATUSES = [
        self::MATERNITY_STATUS_WAITING => 'Waiting for Assessment',
        self::MATERNITY_STATUS_ASSESSMENT_IN_PROGRESS => 'Assessment in Progress',
        self::MATERNITY_STATUS_AWAITING_CLINICIAN => 'Awaiting Clinician',
        self::MATERNITY_STATUS_CONSULTATION_IN_PROGRESS => 'Consultation in Progress',
        self::MATERNITY_STATUS_AWAITING_INVESTIGATION => 'Awaiting Investigation',
        self::MATERNITY_STATUS_TREATMENT_IN_PROGRESS => 'Treatment in Progress',
        self::MATERNITY_STATUS_FOLLOW_UP_REQUIRED => 'Follow-up Required',
        self::MATERNITY_STATUS_COMPLETED => 'Completed',
        self::MATERNITY_STATUS_ADMISSION_RECOMMENDED => 'Admission Recommended',
        self::MATERNITY_STATUS_ADMITTED => 'Admitted',
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * ReceptionVisitService — never trust a status change supplied directly
     * by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_CHECKED_IN => [self::STATUS_WAITING, self::STATUS_CANCELLED],
        self::STATUS_WAITING => [self::STATUS_READY_FOR_SERVICE, self::STATUS_CANCELLED],
        self::STATUS_READY_FOR_SERVICE => [self::STATUS_SENT_TO_SERVICE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_SENT_TO_SERVICE => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'appointment_id',
        'department_id',
        'practitioner_id',
        'visit_type',
        'visit_purpose',
        'status',
        'maternity_status',
        'completed_at',
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
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $visit): void {
            if (empty($visit->visit_number)) {
                $visit->visit_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'visit_number');
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
     * The patient this visit is for.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The scheduled appointment this visit was checked in for, if any —
     * null for a walk-in/follow-up/other visit with no prior appointment.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The department this visit is destined for.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The practitioner selected at reception (Step 62 fix) — drives the
     * consultation charge that must be cleared before the patient is sent to
     * the clinical queue. Null means no specific provider was selected and
     * no consultation charge requirement was created (no-charge rule applies).
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function clinicalEncounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class);
    }

    public function clinicalEncounter(): HasOne
    {
        return $this->hasOne(ClinicalEncounter::class)->latestOfMany();
    }

    public function maternityProfile(): HasOne
    {
        return $this->hasOne(MaternityProfile::class);
    }

    public function isMaternity(): bool
    {
        $deptCode = strtoupper(trim((string) ($this->department?->code ?? '')));
        $deptName = strtolower(trim((string) ($this->department?->name ?? '')));

        return $deptCode === 'MATERNITY' || str_contains($deptName, 'maternity') || filled($this->visit_purpose);
    }
}
