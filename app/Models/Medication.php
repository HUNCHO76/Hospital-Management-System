<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\MedicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The hospital's authoritative medication catalog entry (Phase 6, Step 45)
 * — master data, not a clinical record. A Prescription references a row
 * here rather than duplicating its identity, mirroring LaboratoryTest's
 * relationship to LaboratoryOrder and RadiologyProcedure's to RadiologyOrder.
 * `nhif_code`/`price` preserve the NHIF/billing integration boundary (spec
 * §15/§16) without any claims/billing logic reading or writing them yet.
 *
 * Historical integrity (spec §11/§13): `code` becomes immutable once any
 * Prescription references it (see MedicationService::update()), and a
 * medication is deactivated rather than deleted — a historical
 * prescription's FK never breaks, and every configuration change is fully
 * audited. Mirrors LaboratoryTest/RadiologyProcedure's own precedent
 * exactly. Prescription itself already stores its own strength/dose/route
 * snapshot (see Prescription's fillable), so a later change to this
 * catalog's own strength/form/route never silently rewrites what an old
 * prescription displays.
 *
 * No facility/hospital scoping exists here, deliberately: every other
 * clinical catalog in this app (DiagnosisCode, LaboratoryTest,
 * RadiologyProcedure) is a single global catalog shared across every
 * hospital, not duplicated or configured per facility. Spec §12's
 * "facility configuration" is satisfied by following that existing,
 * already-proven precedent rather than introducing a new architecture this
 * codebase has never needed for a sibling catalog.
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
     * The dosage forms recognized by the catalog (spec §7). A plain
     * validated list, not a separate lookup table — mirrors
     * `LaboratoryTest::SPECIMEN_TYPES`/`RadiologyProcedure::MODALITIES`'s
     * own precedent: this vocabulary is standard pharmaceutical
     * terminology, not something a hospital administrator needs to
     * reconfigure.
     *
     * @var list<string>
     */
    public const FORMS = [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'cream',
        'ointment', 'drops', 'suppository', 'inhaler', 'powder', 'solution', 'other',
    ];

    /**
     * The routes of administration recognized by the catalog (spec §9) —
     * the catalog's own typical/default route, a separate vocabulary from
     * `Prescription::ROUTES` (the route actually chosen for one specific
     * order), the same way `RadiologyProcedure::LATERALITY_OPTIONS`
     * describes a catalog-level default rather than a per-order decision.
     *
     * @var list<string>
     */
    public const ROUTES = [
        'oral', 'intravenous', 'intramuscular', 'subcutaneous', 'topical', 'rectal',
        'vaginal', 'ophthalmic', 'otic', 'nasal', 'inhalation', 'sublingual', 'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'generic_name',
        'brand_name',
        'strength',
        'form',
        'route',
        'unit',
        'category',
        'description',
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
            'price' => 'decimal:2',
        ];
    }

    /**
     * Whether this medication has ever been referenced by a prescription —
     * used to lock its `code` once clinically committed (spec §6, mirrors
     * LaboratoryTest::isReferenced()/RadiologyProcedure::isReferenced()).
     */
    public function isReferenced(): bool
    {
        return $this->prescriptions()->exists();
    }

    /**
     * The prescriptions written against this medication.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * The user who created this medication.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this medication.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
