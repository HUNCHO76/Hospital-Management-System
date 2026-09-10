<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use App\Models\Concerns\HasStatus;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use GeneratesBusinessNumber, HasFactory, HasStatus, SoftDeletes;

    /**
     * The prefix used when generating staff numbers.
     */
    public const NUMBER_PREFIX = 'STF';

    /**
     * Status values recognized for a staff member.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Employment types recognized for a staff member.
     */
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'locum'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'hospital_id',
        'department_id',
        'staff_number',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'email',
        'professional_registration_no',
        'job_title',
        'employment_type',
        'status',
        'hired_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hired_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $staff): void {
            if (empty($staff->staff_number)) {
                $staff->staff_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'staff_number');
            }
        });
    }

    /**
     * The user account associated with this staff member, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The hospital this staff member is employed at.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The department this staff member is assigned to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The practitioner profile for this staff member, if they provide clinical services.
     */
    public function practitioner(): HasOne
    {
        return $this->hasOne(Practitioner::class);
    }

    /**
     * Determine whether this staff member has a practitioner profile.
     */
    public function isPractitioner(): bool
    {
        return $this->practitioner()->exists();
    }

    /**
     * Get the staff member's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim(collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' '));
    }
}
