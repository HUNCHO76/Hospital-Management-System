<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HMS hospital admission record (Phase 8, Step 70).
 */
class Admission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCHARGED = 'discharged';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TRANSFERRED = 'transferred';

    /** @var list<string> */
    protected $fillable = [
        'admission_number',
        'hospital_id',
        'patient_id',
        'clinical_encounter_id',
        'patient_insurance_id',
        'admission_type',
        'ward_type',
        'room_type',
        'diagnosis_at_admission',
        'practitioner_id',
        'charges_per_day',
        'notes',
        'status',
        'admitted_at',
        'discharged_at',
        'discharge_type',
        'discharge_reason',
        'admitted_by',
        'discharged_by',
    ];

    protected function casts(): array
    {
        return [
            'charges_per_day' => 'decimal:2',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function patientInsurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    public function admittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admitted_by');
    }

    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AdmissionTransfer::class);
    }

    public function nhifAdmission(): HasOne
    {
        return $this->hasOne(NhifAdmission::class);
    }

    public function nhifClaims(): HasMany
    {
        return $this->hasMany(NhifClaim::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDischarged(): bool
    {
        return $this->status === self::STATUS_DISCHARGED;
    }
}
