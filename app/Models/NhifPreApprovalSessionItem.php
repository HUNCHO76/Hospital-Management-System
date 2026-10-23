<?php

namespace App\Models;

use Database\Factories\NhifPreApprovalSessionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhifPreApprovalSessionItem extends Model
{
    /** @use HasFactory<NhifPreApprovalSessionItemFactory> */
    use HasFactory;

    public const SOURCE_CONSULTATION = 'consultation';

    public const SOURCE_LAB = 'lab';

    public const SOURCE_MEDICATION = 'medication';

    public const SOURCE_RADIOLOGY = 'radiology';

    /** Awaiting NHIF response (initial state before RequestServices is called). */
    public const APPROVAL_STATUS_PENDING = 'pending';

    /** NHIF returned status=Accepted + requiresApproval=false — immediately issuable. */
    public const APPROVAL_STATUS_ACCEPTED = 'accepted';

    /** NHIF returned requiresApproval=true — waiting for NHIF approval decision. */
    public const APPROVAL_STATUS_PENDING_APPROVAL = 'pending_approval';

    /** Approved via NHIF approval flow (requiresApproval was true, now approved). */
    public const APPROVAL_STATUS_APPROVED = 'approved';

    /** NHIF rejected this service. Cannot be issued. */
    public const APPROVAL_STATUS_REJECTED = 'rejected';

    /** Issued to NHIF via IssueRequestServices. */
    public const APPROVAL_STATUS_ISSUED = 'issued';

    /** NHIF returned an unrecognised status. Block until resolved. */
    public const APPROVAL_STATUS_UNKNOWN = 'unknown';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nhif_pre_approval_session_id',
        'source_type',
        'source_id',
        'nhif_item_code',
        'nhif_item_name',
        'nhif_requested_service_id',
        'nhif_request_id',
        'nhif_item_name_returned',
        'nhif_status',
        'nhif_requires_approval',
        'nhif_effective_date',
        'nhif_end_date',
        'nhif_available_after_date',
        'nhif_percent_covered',
        'quantity',
        'unit_price',
        'approval_reference_no',
        'approval_status',
        'quantity_approved',
        'quantity_issued',
        'rejection_reason',
        'nhif_rejection_details',
        'nhif_rejection_reason_code',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'quantity_approved' => 'integer',
            'quantity_issued' => 'integer',
            'nhif_requires_approval' => 'boolean',
            'nhif_effective_date' => 'date',
            'nhif_end_date' => 'date',
            'nhif_available_after_date' => 'datetime',
            'nhif_percent_covered' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(NhifPreApprovalSession::class, 'nhif_pre_approval_session_id');
    }

    public function radiologyOrderItem(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrderItem::class, 'source_id');
    }

    /** Accepted directly from RequestServices — no approval polling needed. */
    public function isAccepted(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_ACCEPTED;
    }

    /** Approved via NHIF approval flow. */
    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_APPROVED;
    }

    /** Can be issued (either accepted directly or approved via flow). */
    public function isIssuable(): bool
    {
        return $this->isAccepted() || $this->isApproved();
    }

    public function isIssued(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_ISSUED;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_REJECTED;
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_PENDING_APPROVAL;
    }

    public function isUnknown(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_UNKNOWN;
    }

    /**
     * Normalise the raw NHIF service status + requiresApproval flag into an
     * internal approval_status constant.
     *
     * Rules (per the verified NHIF contract):
     *   status=Accepted, requiresApproval=false → ACCEPTED (immediately issuable)
     *   requiresApproval=true                   → PENDING_APPROVAL (must wait for NHIF approval)
     *   status=Rejected                         → REJECTED
     *   unknown status string                   → UNKNOWN (block until resolved)
     */
    public static function normaliseNhifStatus(string $nhifStatus, bool $requiresApproval): string
    {
        $status = strtolower(trim($nhifStatus));

        if ($status === 'rejected') {
            return self::APPROVAL_STATUS_REJECTED;
        }

        if ($status === 'accepted' || $status === 'approved') {
            return $requiresApproval
                ? self::APPROVAL_STATUS_PENDING_APPROVAL
                : self::APPROVAL_STATUS_ACCEPTED;
        }

        if ($requiresApproval) {
            return self::APPROVAL_STATUS_PENDING_APPROVAL;
        }

        return self::APPROVAL_STATUS_UNKNOWN;
    }
}
