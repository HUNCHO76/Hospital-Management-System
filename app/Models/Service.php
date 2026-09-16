<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The central billing service catalog entry (Phase 7, Step 53) — "what the
 * hospital can charge for." See the create_services_table migration's own
 * docblock for the full reasoning behind its shape (no department_id, no
 * separate service_type, the serviceable polymorphic pointer).
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasStatus;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /**
     * The classification vocabulary (spec §8/§9, merged into one field —
     * see the migration's own docblock for why). A plain validated list,
     * not a manageable table — mirrors `RadiologyProcedure::MODALITIES`'s
     * own precedent: this is standard hospital-service classification, not
     * something an administrator needs to reconfigure (spec §8's own "do
     * not create duplicate category systems" — satisfied by not building a
     * second, redundant category table at all).
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'consultation', 'laboratory', 'radiology', 'pharmacy', 'procedure',
        'emergency', 'dental', 'maternity', 'inpatient', 'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'serviceable_type',
        'serviceable_id',
        'is_billable',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_billable' => 'boolean',
        ];
    }

    /**
     * Whether this service has ever been priced — used to lock its `code`
     * once operationalized (spec §7/§37), mirroring
     * LaboratoryTest::isReferenced()/RadiologyProcedure::isReferenced()'s
     * own "immutable once committed" precedent, applied at the point a
     * service actually enters financial use rather than at creation.
     */
    public function isReferenced(): bool
    {
        return $this->prices()->exists();
    }

    /**
     * The clinical/operational module row this billing service represents,
     * if any (e.g. a LaboratoryTest or RadiologyProcedure). Never eager-load
     * this across a mixed collection of services without grouping by type
     * first — a morphTo cannot batch-load heterogeneous related models
     * efficiently.
     */
    public function serviceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every price ever set for this service, across every hospital and
     * every effective period — the service's own full pricing history.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /**
     * The user who created this service.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this service.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
