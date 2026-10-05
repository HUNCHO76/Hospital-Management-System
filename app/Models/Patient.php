<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use App\Models\Concerns\HasStatus;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use GeneratesBusinessNumber, HasFactory, HasStatus, SoftDeletes;

    /**
     * The prefix used when generating patient numbers.
     */
    public const NUMBER_PREFIX = 'PT';

    /**
     * Status values recognized for a patient.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DECEASED = 'deceased';

    /**
     * Marital status values recognized for a patient.
     */
    public const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed'];

    /**
     * Blood group values recognized for a patient.
     */
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'patient_number',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'email',
        'national_id',
        'passport_number',
        'other_identification',
        'occupation',
        'marital_status',
        'blood_group',
        'address',
        'region',
        'district',
        'ward',
        'status',
    ];

    /**
     * The attributes that should be hidden from the model's array/JSON form
     * — the patient's show/edit pages pass this model directly as an
     * Inertia prop, so this is what actually keeps internal bookkeeping
     * columns out of the frontend payload, not a Resource whitelist.
     *
     * @var list<string>
     */
    protected $hidden = ['deleted_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $patient): void {
            if (empty($patient->patient_number)) {
                $patient->patient_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'patient_number');
            }
        });
    }

    /**
     * The emergency/contact persons for this patient.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class);
    }

    /**
     * The insurance records held by this patient.
     */
    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    /**
     * The clinical encounters recorded for this patient. Used by
     * ClinicalHistoryService to build the clinical timeline — see
     * PatientHistoryService for the separate, non-clinical activity
     * timeline.
     */
    public function clinicalEncounters(): HasMany
    {
        return $this->hasMany(ClinicalEncounter::class);
    }

    /**
     * The patient's currently active, NHIF-verified insurance record with a
     * captured scheme ID. Returns the most recent such record, or null if the
     * patient has no active NHIF coverage.
     */
    public function activeNhifInsurance(): ?PatientInsurance
    {
        return $this->insurances()
            ->where('status', PatientInsurance::STATUS_ACTIVE)
            ->where('verification_status', PatientInsurance::VERIFICATION_VERIFIED)
            ->whereNotNull('nhif_authorization_no')
            ->whereNotNull('nhif_scheme_id')
            ->with('insuranceScheme:id,name')
            ->latest()
            ->first();
    }

    /**
     * Get the patient's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim(collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' '));
    }
}
