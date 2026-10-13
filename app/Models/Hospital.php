<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\HospitalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hospital extends Model
{
    /** @use HasFactory<HospitalFactory> */
    use HasFactory, HasStatus, SoftDeletes;

    /**
     * Status values recognized for a hospital.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'registration_number',
        'phone',
        'email',
        'address',
        'region',
        'district',
        'ward',
        'status',
        'default_consultation_service_id',
    ];

    /**
     * The departments belonging to this hospital.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * The staff employed at this hospital.
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * The facility-wide consultation service ChargeCaptureService falls
     * back to when the assigned practitioner has no practitioner-specific
     * consultation_service_id configured.
     */
    public function defaultConsultationService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'default_consultation_service_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
