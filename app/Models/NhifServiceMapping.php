<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HMS service → NHIF item code mapping per hospital (Phase 8, Step 68).
 */
class NhifServiceMapping extends Model
{
    public const COVERAGE_COVERED = 'covered';

    public const COVERAGE_PARTIALLY_COVERED = 'partially_covered';

    public const COVERAGE_EXCLUDED = 'excluded';

    public const COVERAGE_NOT_MAPPED = 'not_mapped';

    public const COVERAGE_UNKNOWN = 'unknown';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYNC = 'sync';

    /** @var list<string> */
    protected $fillable = [
        'service_id',
        'hospital_id',
        'nhif_item_code',
        'nhif_item_name',
        'nhif_package_code',
        'nhif_package_name',
        'coverage_status',
        'requires_preauth',
        'effective_from',
        'effective_to',
        'is_active',
        'mapping_source',
        'last_synced_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_preauth' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(NhifServicePrice::class);
    }

    public function isCovered(): bool
    {
        return in_array($this->coverage_status, [self::COVERAGE_COVERED, self::COVERAGE_PARTIALLY_COVERED], true);
    }

    public function isExcluded(): bool
    {
        return $this->coverage_status === self::COVERAGE_EXCLUDED;
    }

    /** Current active allowable amount, or null when not available. */
    public function currentAllowableAmount(): ?float
    {
        $price = $this->prices()
            ->where('status', 'active')
            ->where('effective_from', '<=', today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->orderByDesc('effective_from')
            ->first();

        return $price ? (float) $price->allowable_amount : null;
    }
}
