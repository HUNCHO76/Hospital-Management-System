<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\LaboratoryTestCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laboratory test category (e.g. Hematology, Clinical Chemistry) — a
 * small, hospital-configurable lookup table (spec §8), mirroring
 * Department's own shape. Not deletable once created, only deactivated
 * (spec §36/§23) — the same no-hard-delete precedent every other clinical
 * catalog in this app follows.
 */
class LaboratoryTestCategory extends Model
{
    /** @use HasFactory<LaboratoryTestCategoryFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a laboratory test category.
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
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * The laboratory tests classified under this category.
     */
    public function laboratoryTests(): HasMany
    {
        return $this->hasMany(LaboratoryTest::class, 'category_id');
    }

    /**
     * The user who created this category.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this category.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
