<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\RadiologyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A radiology report — the findings/impression a radiologist (or other
 * designated reporting practitioner) records against one COMPLETED
 * RadiologyAppointment (Phase 5, Step 42). One row per examination (unique
 * `radiology_appointment_id`), reached transitively to patient/encounter/
 * order/order item/procedure through that appointment — never a duplicated
 * identity column, mirroring every other Radiology table in this app. Ends
 * at SUBMITTED as far as Step 42 itself goes. Phase 5, Step 43 extends the
 * same row with the verification/return-for-correction workflow (VERIFIED,
 * and RETURNED becoming reachable) rather than a separate verification
 * entity — see the Step 43 migration's own doc-comment for why.
 */
class RadiologyReport extends Model
{
    /** @use HasFactory<RadiologyReportFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating report numbers.
     */
    public const NUMBER_PREFIX = 'RAD-RPT';

    /**
     * The report lifecycle (Phase 5, Steps 42-43, Part 3): DRAFT ->
     * SUBMITTED -> VERIFIED (approved), or SUBMITTED -> RETURNED ->
     * (corrected, which resets it to DRAFT) -> SUBMITTED again. No
     * persisted UNDER_REVIEW status exists (Part 9's own explicit "do not
     * create it unnecessarily" — conflicting concurrent verification is
     * prevented by row locking plus the status check inside
     * verify()/returnForCorrection() itself, not by an intermediate
     * persisted state). RELEASED is deliberately not introduced here —
     * that belongs to Step 44.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_VERIFIED = 'verified';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_RETURNED,
        self::STATUS_VERIFIED,
    ];

    /**
     * The controlled set of reasons a report may be returned for correction
     * (Phase 5, Step 43, Part 11).
     *
     * @var list<string>
     */
    public const RETURN_REASONS = [
        'incomplete_findings',
        'unclear_impression',
        'incorrect_technique',
        'missing_recommendation',
        'clinical_inconsistency',
        'wrong_procedure_information',
        'typographical_error',
        'additional_review_required',
        'other',
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * RadiologyReportService — never trust a status change supplied
     * directly by the frontend. VERIFIED is terminal (Part 15's
     * immutability requirement — no normal edit route ever reaches it
     * again).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_RETURNED],
        self::STATUS_RETURNED => [self::STATUS_DRAFT],
        self::STATUS_VERIFIED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'radiology_appointment_id',
        'reporting_practitioner_id',
        'report_number',
        'status',
        'technique',
        'findings',
        'impression',
        'recommendations',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'verifying_practitioner_id',
        'verified_by',
        'verified_at',
        'verification_notes',
        'returned_by',
        'returned_at',
        'return_reason',
        'return_comments',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            if (empty($report->report_number)) {
                $report->report_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'report_number');
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
     * The completed examination this report is for.
     */
    public function radiologyAppointment(): BelongsTo
    {
        return $this->belongsTo(RadiologyAppointment::class);
    }

    /**
     * The practitioner of record for this report's clinical content —
     * always resolved server-side from the authenticated user's own
     * practitioner profile at creation time, never client-supplied.
     */
    public function reportingPractitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'reporting_practitioner_id');
    }

    /**
     * The user who last updated this report, if any.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who submitted this report, if any.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The practitioner who verified this report, if any — always resolved
     * server-side from the authenticated verifier's own practitioner
     * profile, never client-supplied (Phase 5, Step 43, Part 5).
     */
    public function verifyingPractitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'verifying_practitioner_id');
    }

    /**
     * The user who verified this report, if any.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The user who returned this report for correction, if any.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
