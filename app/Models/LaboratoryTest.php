<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\LaboratoryTestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The hospital's authoritative laboratory test catalog entry (Phase 4,
 * Step 29) — master data, not a clinical record. A LaboratoryOrder (Step 28)
 * references a row here rather than duplicating its name, mirroring
 * Medication's relationship to Prescription. `nhif_code`/`price` preserve
 * the NHIF/billing integration boundary (spec §17/18) without any
 * claims/billing logic reading or writing them yet — no general Service/
 * PriceList model exists anywhere in this app to integrate against.
 *
 * Historical integrity (spec §18): rather than versioning or snapshotting
 * (nothing today reads more than a test's identity from an order — no
 * result/reference-range interpretation exists until Step 33), this catalog
 * relies on `code` becoming immutable once any LaboratoryOrder references it
 * (see LaboratoryTestService::update()) plus deactivation instead of
 * deletion — a historical order's FK never breaks, and every configuration
 * change is fully audited.
 */
class LaboratoryTest extends Model
{
    /** @use HasFactory<LaboratoryTestFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a laboratory test.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The specimen types recognized by the catalog (spec §9) — a plain
     * validated list, not a separate lookup table: a hospital's specimen
     * vocabulary is stable enough that a DB-configurable table would be
     * over-engineering for this step.
     *
     * @var list<string>
     */
    public const SPECIMEN_TYPES = [
        'whole_blood', 'serum', 'plasma', 'urine', 'stool',
        'sputum', 'swab', 'csf', 'body_fluid', 'tissue', 'other',
    ];

    /**
     * The specimen containers recognized by the catalog (spec §10).
     *
     * @var list<string>
     */
    public const SPECIMEN_CONTAINERS = [
        'edta_tube', 'plain_tube', 'fluoride_oxalate_tube',
        'sterile_container', 'universal_container', 'swab_transport_medium', 'other',
    ];

    /**
     * The result-type metadata recognized by the catalog (spec §11) — helps
     * future result entry (Step 33); no result-processing logic exists yet.
     *
     * @var list<string>
     */
    public const RESULT_TYPES = [
        'numeric', 'text', 'boolean', 'positive_negative', 'qualitative', 'enum',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'short_name',
        'description',
        'category_id',
        'department_id',
        'specimen_type',
        'specimen_container',
        'collection_notes',
        'result_type',
        'unit',
        'reference_range',
        'result_options',
        'turnaround_time_minutes',
        'default_priority',
        'effective_from',
        'effective_to',
        'nhif_code',
        'price',
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
            'effective_from' => 'date',
            'effective_to' => 'date',
            'price' => 'decimal:2',
            'result_options' => 'array',
        ];
    }

    /**
     * Scope a query to tests currently in effect (spec §17) — nullable
     * bounds mean "no start/end restriction" on that side.
     */
    public function scopeEffective(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    /**
     * Whether this test has ever been referenced by a laboratory order item
     * — used to lock its `code` once clinically committed (spec §6/§34).
     */
    public function isReferenced(): bool
    {
        return $this->orderItems()->exists();
    }

    /**
     * The category this test is classified under.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTestCategory::class, 'category_id');
    }

    /**
     * The department/service unit responsible for this test.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The user who created this test.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this test.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The analyte parameters defined for this test (Batch A).
     * Ordered by display_order so result entry renders them in the configured sequence.
     */
    public function parameters(): HasMany
    {
        return $this->hasMany(LaboratoryTestParameter::class)->orderBy('display_order');
    }

    public function activeParameters(): HasMany
    {
        return $this->hasMany(LaboratoryTestParameter::class)
            ->where('active', true)
            ->orderBy('display_order');
    }

    /**
     * The order items placed for this test (Phase 4, Step 30 — an order
     * holds many tests, so this test's own orders are reached through its
     * items, not a direct LaboratoryOrder relation).
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(LaboratoryOrderItem::class);
    }
}
