<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\LaboratoryBenchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laboratory work area (e.g. Hematology, Chemistry, Microbiology) — a
 * small, hospital-configurable lookup table (Phase 4, Step 32, spec §11),
 * mirroring LaboratoryTestCategory's exact shape. Never a hard-coded enum:
 * a hospital's own bench/work-area vocabulary varies, unlike the
 * clinically stable specimen_type/specimen_container enums. Not deletable
 * once created, only deactivated — the same no-hard-delete precedent every
 * other clinical catalog in this app follows.
 */
class LaboratoryBench extends Model
{
    /** @use HasFactory<LaboratoryBenchFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a laboratory bench.
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
     * The processing records currently or previously assigned to this
     * bench.
     */
    public function processingRecords(): HasMany
    {
        return $this->hasMany(LaboratoryProcessing::class, 'bench_id');
    }

    /**
     * The user who created this bench.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this bench.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
