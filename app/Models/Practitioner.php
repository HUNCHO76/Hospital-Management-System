<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\PractitionerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Practitioner extends Model
{
    /** @use HasFactory<PractitionerFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating practitioner numbers.
     */
    public const NUMBER_PREFIX = 'PRC';

    /**
     * Status values recognized for a practitioner.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * The controlled set of practitioner types recognized by the system.
     *
     * This is the single source of truth for practitioner types — referenced
     * by validation, seeders, and the frontend type-select — so the list
     * never needs to be duplicated elsewhere.
     */
    public const TYPES = [
        'doctor',
        'nurse',
        'pharmacist',
        'laboratory-technician',
        'radiology-technician',
        'radiologist',
        'clinical-officer',
        'dentist',
    ];

    /**
     * Staff job titles that are normally eligible for a practitioner profile,
     * mapped to the practitioner type they default to. Staff in other job
     * titles aren't blocked from having a practitioner profile — job_title is
     * free text — but the UI uses this to guide which staff to surface first.
     *
     * @var array<string, string>
     */
    public const CLINICAL_JOB_TITLES = [
        'Doctor' => 'doctor',
        'Nurse' => 'nurse',
        'Pharmacist' => 'pharmacist',
        'Laboratory Technician' => 'laboratory-technician',
        'Radiology Technician' => 'radiology-technician',
        'Radiologist' => 'radiologist',
        'Clinical Officer' => 'clinical-officer',
        'Dentist' => 'dentist',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'practitioner_number',
        'practitioner_type',
        'professional_registration_number',
        'specialization',
        'license_number',
        'license_expiry_date',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'license_expiry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $practitioner): void {
            if (empty($practitioner->practitioner_number)) {
                $practitioner->practitioner_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'practitioner_number');
            }
        });
    }

    /**
     * The staff record this practitioner profile belongs to.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
