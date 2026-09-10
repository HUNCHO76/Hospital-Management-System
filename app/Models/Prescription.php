<?php

namespace App\Models;

use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The structured medication order for a medication-type CarePlanItem — the
 * doctor's prescribing decision, distinct from pharmacy dispensing (spec §9),
 * which is out of scope for this step. One prescription per item; a dose
 * change once the item is completed is a new item, not a silent edit here
 * (see CarePlanService::correctItem()'s class docs).
 */
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /**
     * The controlled set of administration routes recognized by the system.
     *
     * @var list<string>
     */
    public const ROUTES = [
        'oral',
        'intravenous',
        'intramuscular',
        'subcutaneous',
        'topical',
        'rectal',
        'inhalation',
        'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'care_plan_item_id',
        'medication_id',
        'strength',
        'dose',
        'route',
        'frequency',
        'duration',
        'quantity',
        'instructions',
        'created_by',
    ];

    /**
     * The care plan item this prescription is the medication detail for.
     */
    public function carePlanItem(): BelongsTo
    {
        return $this->belongsTo(CarePlanItem::class);
    }

    /**
     * The prescribed medication.
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * The user who wrote this prescription.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
