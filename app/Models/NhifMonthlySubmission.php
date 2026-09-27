<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhifMonthlySubmission extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hospital_id',
        'facility_code',
        'claim_year',
        'claim_month',
        'folios_submitted',
        'total_amount_claimed',
        'submission_remarks',
        'nhif_response',
        'status',
        'submitted_at',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'claim_year' => 'integer',
            'claim_month' => 'integer',
            'folios_submitted' => 'integer',
            'total_amount_claimed' => 'decimal:2',
            'nhif_response' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
