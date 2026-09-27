<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NhifBillConfirmation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hospital_id',
        'patient_insurance_id',
        'nhif_authorization_no',
        'card_no',
        'facility_code',
        'attendance_date',
        'total_amount',
        'confirmation_code',
        'confirmation_status',
        'nhif_response',
        'confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'total_amount' => 'decimal:2',
            'nhif_response' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmation_status === self::STATUS_CONFIRMED;
    }
}
