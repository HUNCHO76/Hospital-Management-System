<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\InsuranceProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InsuranceProvider extends Model
{
    /** @use HasFactory<InsuranceProviderFactory> */
    use HasFactory, HasStatus, SoftDeletes;

    /**
     * Status values recognized for an insurance provider.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The controlled set of provider types recognized by the system.
     */
    public const TYPES = ['government', 'private', 'corporate', 'other'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'registration_number',
        'type',
        'phone',
        'email',
        'address',
        'api_enabled',
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
            'api_enabled' => 'boolean',
        ];
    }

    /**
     * The schemes offered by this insurance provider.
     */
    public function schemes(): HasMany
    {
        return $this->hasMany(InsuranceScheme::class);
    }

    /**
     * The patient insurance records under this provider.
     */
    public function patientInsurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }
}
