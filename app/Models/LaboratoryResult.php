<?php

namespace App\Models;

use Database\Factories\LaboratoryResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The result for a single requested test (Phase 4, Steps 33-35) — one row
 * per LaboratoryOrderItem, created automatically the moment that item's
 * processing reaches RESULT_PENDING (see LaboratoryResultService::
 * initializeForItem(), called from LaboratoryProcessingController::complete()
 * — mirrors Step 32's own controller-orchestrated auto-initialization
 * pattern). `result_type`/`unit`/`reference_range`/`result_options` are
 * snapshotted from the catalog at that moment rather than read live,
 * because LaboratoryTestService::update() allows those fields to change
 * even after a test has been referenced by an order.
 *
 * Ends at RELEASED. Release itself lives directly on this row
 * (released_by/released_at/release_comment), mirroring verified_by/
 * authorized_by's own precedent; the printable/downloadable *report* — a
 * separate, order-level, versioned document aggregating every released
 * result on that order — is LaboratoryReport's job (see its own docblock
 * for why release and report generation are deliberately two different
 * granularities).
 */
class LaboratoryResult extends Model
{
    /** @use HasFactory<LaboratoryResultFactory> */
    use HasFactory;

    /**
     * The full result lifecycle (Step 33 spec §10, extended by Step 34 spec
     * §5 and Step 35 spec Part 3): RESULT_PENDING -> DRAFT ->
     * PENDING_VERIFICATION -> UNDER_REVIEW -> VERIFIED -> AUTHORIZED ->
     * RELEASED. UNDER_REVIEW can instead branch to RETURNED_FOR_CORRECTION,
     * which re-enters normal draft editing (RETURNED_FOR_CORRECTION ->
     * DRAFT) and rejoins the exact same DRAFT -> PENDING_VERIFICATION path
     * every other draft uses — no second submission mechanism.
     * VERIFIED/AUTHORIZED/RELEASED can revert to PENDING_VERIFICATION only
     * via a controlled, audited amendment (LaboratoryResultService::
     * amend()), forcing full re-review, re-verification, and
     * re-authorization — never a casual/direct status change. Amending a
     * RELEASED result additionally supersedes that order's current report
     * version (LaboratoryReportService::supersedeActiveForOrder(), called by
     * the controller — see LaboratoryResultController::amend()).
     */
    public const STATUS_RESULT_PENDING = 'result_pending';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RETURNED_FOR_CORRECTION = 'returned_for_correction';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_RELEASED = 'released';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_RESULT_PENDING,
        self::STATUS_DRAFT,
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RETURNED_FOR_CORRECTION,
        self::STATUS_VERIFIED,
        self::STATUS_AUTHORIZED,
        self::STATUS_RELEASED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * LaboratoryResultService — never trust a status change supplied
     * directly by the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_RESULT_PENDING => [self::STATUS_DRAFT],
        self::STATUS_DRAFT => [self::STATUS_PENDING_VERIFICATION],
        self::STATUS_PENDING_VERIFICATION => [self::STATUS_UNDER_REVIEW],
        self::STATUS_UNDER_REVIEW => [self::STATUS_VERIFIED, self::STATUS_RETURNED_FOR_CORRECTION],
        self::STATUS_RETURNED_FOR_CORRECTION => [self::STATUS_DRAFT],
        self::STATUS_VERIFIED => [self::STATUS_AUTHORIZED, self::STATUS_PENDING_VERIFICATION],
        self::STATUS_AUTHORIZED => [self::STATUS_RELEASED, self::STATUS_PENDING_VERIFICATION],
        self::STATUS_RELEASED => [self::STATUS_PENDING_VERIFICATION],
    ];

    /**
     * A result is "ready for release" (spec Part 3/29) once authorized but
     * not yet released — a purely derived reading of `status`, not a stored
     * value, since nothing in this domain distinguishes a further
     * real-world state between authorization and release itself.
     */
    public function isReadyForRelease(): bool
    {
        return $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Whether this result has been released — i.e. is now an official,
     * final laboratory result (spec Part 29), as opposed to any earlier
     * stage which must never be presented as one.
     */
    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    /**
     * Reasons recognized for returning a result for correction (spec §20) —
     * a plain validated list, mirroring LaboratorySample::REJECTION_REASONS'
     * own precedent exactly.
     *
     * @var list<string>
     */
    public const RETURN_REASONS = [
        'incorrect_value',
        'wrong_unit',
        'missing_component',
        'wrong_specimen',
        'transcription_error',
        'insufficient_clinical_information',
        'other',
    ];

    /**
     * Result-type metadata mirrored from LaboratoryTest::RESULT_TYPES
     * (Step 29) — snapshotted per result, not redeclared independently.
     */
    public const RESULT_TYPES = LaboratoryTest::RESULT_TYPES;

    /**
     * Interpretation flags (spec §18) — kept strictly separate from the raw
     * value; never encoded into value_text/value_code itself.
     */
    public const FLAG_NORMAL = 'normal';

    public const FLAG_LOW = 'low';

    public const FLAG_HIGH = 'high';

    public const FLAG_CRITICAL = 'critical';

    public const FLAG_ABNORMAL = 'abnormal';

    /**
     * @var list<string>
     */
    public const ABNORMAL_FLAGS = [
        self::FLAG_NORMAL,
        self::FLAG_LOW,
        self::FLAG_HIGH,
        self::FLAG_CRITICAL,
        self::FLAG_ABNORMAL,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_order_item_id',
        'laboratory_sample_id',
        'laboratory_test_id',
        'status',
        'result_type',
        'unit',
        'reference_range',
        'result_options',
        'value_numeric',
        'value_text',
        'value_code',
        'abnormal_flag',
        'result_comment',
        'entered_by',
        'entered_at',
        'submitted_by',
        'submitted_at',
        'amended_by',
        'amended_at',
        'reviewed_by',
        'reviewed_at',
        'verified_by',
        'verified_at',
        'verification_comment',
        'authorized_by',
        'authorized_at',
        'returned_by',
        'returned_at',
        'return_reason',
        'return_notes',
        'released_by',
        'released_at',
        'release_comment',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_options' => 'array',
            'value_numeric' => 'decimal:4',
            'entered_at' => 'datetime',
            'submitted_at' => 'datetime',
            'amended_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'authorized_at' => 'datetime',
            'returned_at' => 'datetime',
            'released_at' => 'datetime',
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
     * The requested test this result belongs to.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderItem::class, 'laboratory_order_item_id');
    }

    /**
     * The specimen this result was produced from.
     */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(LaboratorySample::class, 'laboratory_sample_id');
    }

    /**
     * The catalog entry this result's configuration was snapshotted from.
     */
    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }

    /**
     * The user who first entered a value for this result, if any.
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * The user who submitted this result for verification, if any.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The user who last amended this result, if any.
     */
    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }

    /**
     * The user who most recently started reviewing this result, if any.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The user who verified this result, if any.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The user who authorized this result, if any.
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /**
     * The user who most recently returned this result for correction, if
     * any.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * The user who released this result, if any.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
