<?php

namespace App\Models;

use Database\Factories\PrescriptionVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pharmacy-owned review of a PrescriptionOrder (Phase 6, Step 47) — a
 * controlled safety gate between prescribing and dispensing, entirely
 * separate from the order's own clinical row (see the create_prescription_
 * verifications_table migration's own docblock for why). A prescription
 * written by a doctor is never dispensable until this reaches VERIFIED.
 */
class PrescriptionVerification extends Model
{
    /** @use HasFactory<PrescriptionVerificationFactory> */
    use HasFactory;

    /**
     * The lifecycle (Phase 6, Step 47, Part 6-7): PENDING_VERIFICATION (set
     * the moment a PrescriptionOrder is created — see
     * PrescriptionVerificationService::initiate(), called from
     * PrescriptionOrderService::create()) -> VERIFIED (terminal for this
     * step; Step 48 owns whatever comes after), or -> RETURNED -> (the
     * prescriber corrects the order through the ordinary
     * PrescriptionOrderService::update() path, then explicitly resubmits) ->
     * PENDING_VERIFICATION again.
     *
     * No DRAFT status: unlike a radiology report's findings, a prescription
     * order has no separately-saved authoring phase of its own — it is
     * created complete in one action (PrescriptionOrder::STATUS_ACTIVE's own
     * docblock: "item selection/review happens client-side before one atomic
     * submission, not as a separately-saved draft row") — so there is
     * nothing for a DRAFT state here to represent.
     *
     * No persisted UNDER_REVIEW status: mirrors RadiologyReport's own
     * explicit precedent — concurrent-review conflicts are prevented by row
     * locking plus the status check inside verify()/returnForCorrection()
     * itself, not by an intermediate persisted state.
     *
     * No separate CANCELLED/REJECTED status: a prescription being cancelled
     * is already fully owned by PrescriptionOrderService::cancel() (the
     * order's own status, cascading to every item) — verify()/
     * returnForCorrection()/resubmit() all re-check the order is still
     * ACTIVE before acting, the same defense-in-depth re-check
     * RadiologyReportService::assertEligibleForReporting() applies. REJECTED
     * is not modeled separately from RETURNED: this step's own target-flow
     * diagram groups "RETURN/REJECT" under a single arrow to "Correction",
     * and no later part of the spec ever names a distinct persisted
     * REJECTED state to transition to.
     */
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_VERIFIED = 'verified';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_RETURNED,
        self::STATUS_VERIFIED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * PrescriptionVerificationService — never trust a status change supplied
     * directly by the frontend. VERIFIED is terminal for this step.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_VERIFICATION => [self::STATUS_VERIFIED, self::STATUS_RETURNED],
        self::STATUS_RETURNED => [self::STATUS_PENDING_VERIFICATION],
        self::STATUS_VERIFIED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'prescription_order_id',
        'status',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'verification_notes',
        'returned_by',
        'returned_at',
        'return_reason',
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

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The prescription order this review is for.
     */
    public function prescriptionOrder(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrder::class);
    }

    /**
     * The user who (most recently) submitted this order for verification.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The user who verified this order, if any.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The user who returned this order for correction, if any.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
