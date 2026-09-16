<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\StockItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "This Medication, held at this hospital's pharmacy" (Phase 6, Step 49) —
 * pure identity plus reorder configuration; batch-specific data (quantity,
 * expiry, cost) always lives on StockBatch, never here (spec §5). One row per
 * (hospital_id, medication_id) pair — see the create_stock_items_table
 * migration's own docblock for why this is hospital-scoped rather than a
 * separate pharmacy-location entity.
 */
class StockItem extends Model
{
    /** @use HasFactory<StockItemFactory> */
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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'hospital_id',
        'medication_id',
        'reorder_level',
        'maximum_level',
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
            'reorder_level' => 'integer',
            'maximum_level' => 'integer',
        ];
    }

    /**
     * The hospital this stock item belongs to.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The catalog entry this stock item tracks inventory for.
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * Every batch ever received against this stock item, most recently
     * expiring first — a plain listing relation; FEFO allocation itself
     * queries StockBatch directly under a lock rather than via this relation
     * (see StockService::allocateFefo()).
     */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class)->orderBy('expiry_date');
    }

    /**
     * Every ledger movement recorded against any batch of this stock item.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    /**
     * The user who created this stock item.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this stock item, if any.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
