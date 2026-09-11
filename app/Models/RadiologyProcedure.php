<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\RadiologyProcedureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The hospital's authoritative radiology procedure catalog entry (Phase 5,
 * Steps 36-37) — master data, not a clinical record. A RadiologyOrder
 * references a row here rather than duplicating its name, mirroring
 * LaboratoryTest's relationship to LaboratoryOrder. `nhif_code`/`price`
 * preserve the NHIF/billing integration boundary without any claims/
 * billing logic reading or writing them yet — no general Service/PriceList
 * model exists anywhere in this app to integrate against (confirmed by
 * audit before this step was built).
 *
 * Historical integrity: `code` becomes immutable once any RadiologyOrder
 * references it (see RadiologyProcedureService::update()), and a procedure
 * is deactivated rather than deleted — a historical order's FK never
 * breaks, and every configuration change is fully audited. Mirrors
 * LaboratoryTest's own precedent exactly.
 */
class RadiologyProcedure extends Model
{
    /** @use HasFactory<RadiologyProcedureFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a radiology procedure.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The modality/category vocabulary (Phase 5, Step 37, Parts 6-7). A
     * single field deliberately serves both the "Category" and "Modality"
     * concepts the spec describes separately — their example value lists
     * are identical, so a second column would only duplicate the same
     * fact and risk drifting out of sync. A plain validated list, not a
     * separate lookup table — mirrors `LaboratoryTest::SPECIMEN_TYPES`'s
     * own precedent: this vocabulary is standard clinical/radiological
     * terminology, not something a hospital administrator needs to
     * reconfigure.
     *
     * @var list<string>
     */
    public const MODALITIES = [
        'x_ray', 'ultrasound', 'ct', 'mri', 'mammography', 'fluoroscopy', 'other',
    ];

    /**
     * The body-site vocabulary (Part 8). A procedure may span more than
     * one site (e.g. `CT-CHEST-ABD`), so this is stored as a JSON list on
     * `body_sites`, not a single string column.
     *
     * @var list<string>
     */
    public const BODY_SITES = [
        'chest', 'abdomen', 'pelvis', 'head', 'brain', 'spine', 'cervical_spine',
        'thoracic_spine', 'lumbar_spine', 'neck', 'upper_limb', 'lower_limb',
        'knee', 'shoulder', 'ankle', 'foot', 'hand', 'wrist', 'skull',
        'breast', 'kidney', 'uterus', 'thyroid', 'scrotum', 'prostate', 'other',
    ];

    /**
     * The laterality vocabulary (Part 9). Describes whether/how laterality
     * typically applies to this *type* of procedure — a catalog-level
     * default, not a fixed clinical decision. The actual laterality
     * selected for one specific patient's order (e.g. "left knee" vs
     * "right knee") belongs on the order item, a future Step 38 concern —
     * this only tells the order-time UI whether to ask.
     *
     * @var list<string>
     */
    public const LATERALITY_OPTIONS = [
        'not_applicable', 'left', 'right', 'bilateral', 'midline',
    ];

    /**
     * The contrast-requirement vocabulary (Part 10) — an operational
     * default only. The catalog is never a substitute for the actual
     * clinical decision made at order/protocol time.
     *
     * @var list<string>
     */
    public const CONTRAST_REQUIREMENTS = [
        'no_contrast', 'optional', 'required',
    ];

    /**
     * The contrast-type vocabulary (Part 10) — only meaningful when
     * `contrast_requirement` is not `no_contrast`.
     *
     * @var list<string>
     */
    public const CONTRAST_TYPES = [
        'oral', 'intravenous', 'rectal', 'other',
    ];

    /**
     * The preparation-requirement vocabulary (Part 12). `preparation_type`
     * is the single source of truth for "does this procedure need
     * preparation" — `none` means no, anything else means yes — rather
     * than a separate boolean that could contradict it.
     *
     * @var list<string>
     */
    public const PREPARATION_TYPES = [
        'none', 'fasting', 'full_bladder', 'empty_bladder', 'oral_preparation',
        'contrast_preparation', 'mri_safety_screening', 'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'modality',
        'description',
        'body_sites',
        'laterality',
        'contrast_requirement',
        'contrast_type',
        'preparation_type',
        'preparation_instructions',
        'turnaround_time_minutes',
        'sort_order',
        'department_id',
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
            'body_sites' => 'array',
            'price' => 'decimal:2',
        ];
    }

    /**
     * Whether this procedure has ever been referenced by a radiology order
     * item — used to lock its `code` once clinically committed, mirroring
     * LaboratoryTest::isReferenced() exactly.
     */
    public function isReferenced(): bool
    {
        return $this->orderItems()->exists();
    }

    /**
     * The department/service unit responsible for this procedure.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The user who created this procedure.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this procedure.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The order items placed for this procedure.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(RadiologyOrderItem::class);
    }
}
