<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\InsuranceSchemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InsuranceScheme extends Model
{
    /** @use HasFactory<InsuranceSchemeFactory> */
    use HasFactory, HasStatus, SoftDeletes;

    /**
     * Status values recognized for an insurance scheme.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'insurance_provider_id',
        'name',
        'code',
        'description',
        'status',
    ];

    /**
     * The insurance provider that offers this scheme.
     */
    public function insuranceProvider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class);
    }

    /**
     * The patient insurance records under this scheme.
     */
    public function patientInsurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    /**
     * Every coverage rule ever configured for this scheme, across every
     * service/category and every effective period (Phase 7, Step 56) — the
     * scheme's own full coverage-configuration history, mirrors
     * Service::prices() exactly.
     */
    public function coverageRules(): HasMany
    {
        return $this->hasMany(InsuranceCoverageRule::class);
    }
}
