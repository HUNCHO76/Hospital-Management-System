<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\MedicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable medication/drug catalog entry — master data, not a clinical
 * record. A Prescription references a row here rather than duplicating its
 * name, keeping the catalog separate from the per-patient prescribing
 * decision (spec §9/§12). A small starter set, not a full formulary — see
 * MedicationSeeder.
 */
class Medication extends Model
{
    /** @use HasFactory<MedicationFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a medication.
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
        'category',
        'status',
    ];

    /**
     * The prescriptions written against this medication.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }
}
