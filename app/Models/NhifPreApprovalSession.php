<?php

namespace App\Models;

use Database\Factories\NhifPreApprovalSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NhifPreApprovalSession extends Model
{
    /** @use HasFactory<NhifPreApprovalSessionFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    /** All items Accepted + requiresApproval=false — immediately issuable without further approval polling. */
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PARTIALLY_APPROVED = 'partially_approved';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'patient_id',
        'patient_insurance_id',
        'hospital_id',
        'clinical_encounter_id',
        'nhif_authorization_no',
        'nhif_request_id',
        'nhif_request_no',
        'nhif_card_no',
        'facility_code',
        'nhif_facility_code_returned',
        'practitioner_no',
        'status',
        'submitted_at',
        'synced_at',
        'issued_at',
        'request_payload',
        'response_payload',
        'issue_payload',
        'issue_response_payload',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'synced_at' => 'datetime',
            'issued_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'issue_payload' => 'array',
            'issue_response_payload' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(NhifPreApprovalSessionItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSubmittable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * A session is issuable when at least some services can be issued:
     *   - STATUS_ACCEPTED: all items accepted immediately (requiresApproval=false)
     *   - STATUS_APPROVED: all items approved via NHIF approval flow
     *   - STATUS_PARTIALLY_APPROVED: mix of accepted/approved and rejected
     */
    public function isIssuable(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACCEPTED,
            self::STATUS_APPROVED,
            self::STATUS_PARTIALLY_APPROVED,
        ], true);
    }
}
