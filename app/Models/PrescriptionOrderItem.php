<?php

namespace App\Models;

use Database\Factories\PrescriptionOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single medication within a PrescriptionOrder (Phase 6, Step 46, "one
 * prescription, many medications"). `medication_code_snapshot`/
 * `medication_name_snapshot`/`strength_snapshot`/`form_snapshot`/
 * `route_snapshot` are captured at prescribing time (mirrors
 * RadiologyOrderItem exactly) so a later catalog rename/deactivation never
 * makes an existing prescription ambiguous. Carries its own status,
 * independently of the parent order's, so an individual medication can be
 * removed without cancelling the whole prescription.
 */
class PrescriptionOrderItem extends Model
{
    /** @use HasFactory<PrescriptionOrderItemFactory> */
    use HasFactory;

    /**
     * The controlled set of administration routes recognized by the system
     * — mirrors the pre-existing Prescription::ROUTES list exactly.
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
     * The controlled set of dosing frequencies recognized by the system — no
     * pre-existing frequency reference data was found anywhere in the
     * codebase (the legacy Prescription.frequency is an unrestricted free
     * string), so this is the first controlled list for it, following the
     * same plain-model-const convention as every other reference list in
     * this app (Medication::FORMS, Prescription::ROUTES, ...).
     *
     * @var list<string>
     */
    public const FREQUENCIES = [
        'once_daily',
        'twice_daily',
        'three_times_daily',
        'four_times_daily',
        'every_4_hours',
        'every_6_hours',
        'every_8_hours',
        'every_12_hours',
        'as_needed',
        'stat',
        'other',
    ];

    /**
     * @var list<string>
     */
    public const DURATION_UNITS = ['days', 'weeks', 'months'];

    /**
     * Status values recognized for a prescription order item. An item is
     * either actively prescribed or removed — no further states are
     * forward-declared here (Step 47's verification status belongs to a
     * future pharmacy-owned entity, never retrofitted here).
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'prescription_order_id',
        'medication_id',
        'medication_code_snapshot',
        'medication_name_snapshot',
        'strength_snapshot',
        'form_snapshot',
        'route_snapshot',
        'dose',
        'dose_unit',
        'frequency',
        'route',
        'duration',
        'duration_unit',
        'quantity',
        'quantity_unit',
        'instructions',
        'safety_override_justification',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'quantity' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a transition from the current status to the given
     * status is allowed.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * The order this item belongs to.
     */
    public function prescriptionOrder(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrder::class);
    }

    /**
     * The catalog entry this item was prescribed from.
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * The user who cancelled this item, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Every dispensing fulfillment record for this item across every
     * Dispensing event (Phase 6, Step 48) — summing the non-cancelled rows
     * here is how "quantity already dispensed" and "remaining quantity" are
     * always derived, never stored directly on this row.
     */
    public function dispensingItems(): HasMany
    {
        return $this->hasMany(DispensingItem::class);
    }
}
