<?php

namespace App\Models;

use Database\Factories\RadiologyAppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A scheduled radiology examination slot for one RadiologyOrderItem (Phase
 * 5, Step 39) — deliberately its own table rather than a reuse of the
 * generic Appointment model, since that model's scheduling axis is a
 * mandatory practitioner_id, not a room/resource. See the migration's own
 * doc-comment for the full reuse-vs-extend reasoning.
 *
 * Phase 5, Step 40 extends the same row with the arrival -> verification ->
 * preparation -> readiness pipeline, rather than a new entity — this keeps
 * each procedure's own progress independent of every other procedure on
 * the same order (Part 25's "prefer readiness at order-item level").
 *
 * Phase 5, Step 41 extends the same row once more with the actual
 * examination: READY -> IN_PROGRESS -> COMPLETED/ABORTED. See this step's
 * own migration doc-comment for why no separate radiology_examinations
 * table was created.
 */
class RadiologyAppointment extends Model
{
    /** @use HasFactory<RadiologyAppointmentFactory> */
    use HasFactory;

    /**
     * Status values recognized for a radiology appointment.
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_PREPARATION_PENDING = 'preparation_pending';

    public const STATUS_PREPARATION_IN_PROGRESS = 'preparation_in_progress';

    public const STATUS_READY = 'ready';

    public const STATUS_NOT_READY = 'not_ready';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    /**
     * The actual examination (Phase 5, Step 41).
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_ARRIVED,
        self::STATUS_PREPARATION_PENDING,
        self::STATUS_PREPARATION_IN_PROGRESS,
        self::STATUS_READY,
        self::STATUS_NOT_READY,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_ABORTED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Statuses that still occupy the resource's schedule and therefore
     * count toward a double-booking conflict, toward a RadiologyOrder's
     * SCHEDULED rollup (Step 39), and toward an item's "already has an
     * active appointment" scheduling-eligibility check (Step 39/41) —
     * everything except CANCELLED/NO_SHOW/ABORTED. An arrived/preparing/
     * ready/in-progress patient obviously still occupies their slot; a
     * COMPLETED examination is also included so the item is never offered
     * for re-scheduling once genuinely finished. ABORTED is deliberately
     * excluded (Part 14) — an aborted examination's item must be freely
     * re-schedulable for a fresh attempt, the same way NO_SHOW already is.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_ARRIVED,
        self::STATUS_PREPARATION_PENDING,
        self::STATUS_PREPARATION_IN_PROGRESS,
        self::STATUS_READY,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    /**
     * Statuses from which a *time* change still makes sense — once a
     * patient has arrived, "rescheduling" no longer means anything (they
     * are physically present), so this is deliberately narrower than
     * ACTIVE_STATUSES.
     *
     * @var list<string>
     */
    public const RESCHEDULABLE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
    ];

    /**
     * The controlled set of reasons a patient may be marked not ready
     * (Phase 5, Step 40, Part 15).
     */
    public const NOT_READY_REASONS = [
        'preparation_incomplete',
        'identity_issue',
        'consent_pending',
        'clinical_review_required',
        'equipment_unavailable',
        'patient_condition_changed',
        'other',
    ];

    /**
     * The controlled set of reasons an examination may be aborted (Phase 5,
     * Step 41, Part 14).
     *
     * @var list<string>
     */
    public const ABORT_REASONS = [
        'patient_unable_to_tolerate',
        'equipment_problem',
        'patient_condition_changed',
        'patient_refused',
        'incorrect_preparation',
        'safety_concern',
        'technical_failure',
        'other',
    ];

    /**
     * The modality-specific technical fields recognized in
     * `technical_details` (Phase 5, Step 41, Part 9) — deliberately narrow,
     * operational fields only; clinical interpretation belongs to the
     * radiologist/reporting stage (Step 42), never to this technician-facing
     * capture. Keyed by RadiologyProcedure::MODALITIES; a modality with no
     * entry here (e.g. `other`) accepts no structured fields beyond the
     * free-text `technical_notes` column.
     *
     * @var array<string, list<string>>
     */
    public const TECHNICAL_FIELDS_BY_MODALITY = [
        'x_ray' => ['view', 'body_part', 'laterality'],
        'ultrasound' => ['body_site', 'scan_type'],
        'ct' => ['body_region', 'protocol', 'contrast_used'],
        'mri' => ['body_region', 'protocol', 'coil'],
        'mammography' => ['view', 'laterality'],
        'fluoroscopy' => ['body_part'],
    ];

    public const CONSENT_NOT_REQUIRED = 'not_required';

    public const CONSENT_PENDING = 'pending';

    public const CONSENT_OBTAINED = 'obtained';

    public const CONSENT_DECLINED = 'declined';

    /**
     * @var list<string>
     */
    public const CONSENT_STATUSES = [
        self::CONSENT_NOT_REQUIRED,
        self::CONSENT_PENDING,
        self::CONSENT_OBTAINED,
        self::CONSENT_DECLINED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * RadiologySchedulingService/RadiologyReceptionService — never trust a
     * status change supplied directly by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_CONFIRMED, self::STATUS_ARRIVED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CONFIRMED => [self::STATUS_ARRIVED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_ARRIVED => [self::STATUS_PREPARATION_PENDING, self::STATUS_READY, self::STATUS_NOT_READY, self::STATUS_CANCELLED],
        self::STATUS_PREPARATION_PENDING => [self::STATUS_PREPARATION_IN_PROGRESS, self::STATUS_NOT_READY, self::STATUS_CANCELLED],
        self::STATUS_PREPARATION_IN_PROGRESS => [self::STATUS_READY, self::STATUS_NOT_READY, self::STATUS_CANCELLED],
        self::STATUS_NOT_READY => [self::STATUS_ARRIVED, self::STATUS_CANCELLED],
        // READY -> IN_PROGRESS is the examination start (Step 41); READY ->
        // CANCELLED remains reachable (e.g. the patient leaves before the
        // examination itself begins).
        self::STATUS_READY => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        // Exactly the Part 3 exception: once an examination is under way it
        // only ever finishes (COMPLETED) or is aborted with a reason
        // (ABORTED) — never a plain CANCELLED, and never back to READY.
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_ABORTED],
        // Terminal — a completed examination is never reopened this step
        // (Part 6's "any reopening capability must be separately authorized"
        // is not built here).
        self::STATUS_COMPLETED => [],
        // Terminal — but the *item* it belonged to remains re-schedulable
        // for a fresh examination attempt (see ACTIVE_STATUSES above).
        self::STATUS_ABORTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'radiology_order_item_id',
        'modality',
        'resource_name',
        'scheduled_start',
        'scheduled_end',
        'status',
        'notes',
        'scheduled_by',
        'confirmed_at',
        'confirmed_by',
        'rescheduled_at',
        'reschedule_reason',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'arrived_at',
        'arrived_by',
        'verified_at',
        'verified_by',
        'instructions_given_at',
        'instructions_given_by',
        'preparation_completed_at',
        'preparation_completed_by',
        'preparation_notes',
        'preparation_waived_at',
        'preparation_waived_by',
        'preparation_waived_reason',
        'mri_screening_completed_at',
        'mri_screening_completed_by',
        'mri_screening_flagged',
        'mri_screening_notes',
        'contrast_reviewed_at',
        'contrast_reviewed_by',
        'consent_status',
        'consent_recorded_at',
        'consent_recorded_by',
        'not_ready_reason',
        'ready_at',
        'ready_by',
        'performed_by',
        'started_at',
        'completed_at',
        'completed_by',
        'technical_notes',
        'technical_details',
        'aborted_at',
        'aborted_by',
        'abort_reason',
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
            'confirmed_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'arrived_at' => 'datetime',
            'verified_at' => 'datetime',
            'instructions_given_at' => 'datetime',
            'preparation_completed_at' => 'datetime',
            'preparation_waived_at' => 'datetime',
            'mri_screening_completed_at' => 'datetime',
            'mri_screening_flagged' => 'boolean',
            'contrast_reviewed_at' => 'datetime',
            'consent_recorded_at' => 'datetime',
            'ready_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'technical_details' => 'array',
            'aborted_at' => 'datetime',
        ];
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
     * The order item this appointment schedules.
     */
    public function radiologyOrderItem(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrderItem::class);
    }

    /**
     * The user who scheduled this appointment.
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /**
     * The user who confirmed this appointment, if any.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The user who cancelled this appointment, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The user who marked this appointment's patient as arrived, if any.
     */
    public function arrivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arrived_by');
    }

    /**
     * The user who verified the patient's identity and order, if any.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The user who confirmed preparation instructions were given, if any.
     */
    public function instructionsGivenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructions_given_by');
    }

    /**
     * The user who completed preparation, if any.
     */
    public function preparationCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparation_completed_by');
    }

    /**
     * The user who waived preparation, if any.
     */
    public function preparationWaivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparation_waived_by');
    }

    /**
     * The user who completed MRI safety screening, if any.
     */
    public function mriScreeningCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mri_screening_completed_by');
    }

    /**
     * The user who reviewed the contrast requirement, if any.
     */
    public function contrastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contrast_reviewed_by');
    }

    /**
     * The user who recorded consent status, if any.
     */
    public function consentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_recorded_by');
    }

    /**
     * The user who marked this appointment's patient ready for examination,
     * if any.
     */
    public function readyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

    /**
     * The technician/radiographer who performed the examination, if any
     * (Phase 5, Step 41) — always the authenticated user at start() time,
     * never a value submitted by the client.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * The user who completed the examination, if any.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The user who aborted the examination, if any.
     */
    public function abortedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aborted_by');
    }

    /**
     * The findings/impression report written against this examination, if
     * any (Phase 5, Step 42) — at most one, enforced by a unique index on
     * `radiology_reports.radiology_appointment_id`.
     */
    public function report(): HasOne
    {
        return $this->hasOne(RadiologyReport::class);
    }
}
