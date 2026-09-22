<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NHIF admission submission state for an HMS admission record (Phase 8, Step 70).
 */
class NhifAdmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'nhif_authorization_no',
        'nhif_facility_code',
        'nhif_practitioner_no',
        'nhif_admission_type_id',
        'nhif_ward_type_id',
        'nhif_room_type_id',
        'nhif_admission_no',
        'nhif_status',
        'http_status',
        'raw_request',
        'raw_response',
        'error_message',
        'retry_count',
        'submitted_by',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isAccepted(): bool
    {
        return $this->nhif_status === self::STATUS_ACCEPTED;
    }
}
