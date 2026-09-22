<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hospital admission transfer record, including NHIF transfer submission state (Phase 8, Step 70).
 */
class AdmissionTransfer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const NHIF_STATUS_PENDING = 'pending';

    public const NHIF_STATUS_SUBMITTED = 'submitted';

    public const NHIF_STATUS_ACCEPTED = 'accepted';

    public const NHIF_STATUS_REJECTED = 'rejected';

    public const NHIF_STATUS_NOT_REQUIRED = 'not_required';

    /** @var list<string> */
    protected $fillable = [
        'admission_id',
        'from_hospital_id',
        'to_hospital_id',
        'from_ward_type',
        'to_ward_type',
        'from_room_type',
        'to_room_type',
        'reason',
        'charges_per_day',
        'practitioner_no',
        'practitioners_remarks',
        'status',
        'nhif_admission_no',
        'nhif_status',
        'nhif_transfer_no',
        'nhif_ward_type_id',
        'nhif_room_type_id',
        'http_status',
        'raw_request',
        'raw_response',
        'error_message',
        'requested_by',
        'requested_at',
        'completed_at',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'charges_per_day' => 'decimal:2',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function fromHospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'from_hospital_id');
    }

    public function toHospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'to_hospital_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
