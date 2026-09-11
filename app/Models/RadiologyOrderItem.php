<?php

namespace App\Models;

use Database\Factories\RadiologyOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single requested procedure within a RadiologyOrder (Phase 5, Step 36,
 * Part 9 — "one order, many procedures"). `procedure_code`/`procedure_name`
 * are snapshotted at order time (mirrors LaboratoryOrderItem exactly) so a
 * later catalog rename/deactivation never makes an existing order
 * ambiguous. Carries its own status, independently of the parent order's,
 * so future steps (scheduling/examination/reporting) can progress each
 * procedure at its own pace — only ORDERED/CANCELLED are reachable in this
 * step.
 */
class RadiologyOrderItem extends Model
{
    /** @use HasFactory<RadiologyOrderItemFactory> */
    use HasFactory;

    /**
     * Status values recognized for a radiology order item. Only ORDERED/
     * CANCELLED are reachable until Steps 38-44 build the scheduling/
     * examination/reporting workflow — no future states are
     * forward-declared here (Part 11's explicit instruction not to
     * implement later workflow states this step does not need).
     */
    public const STATUS_ORDERED = 'ordered';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_ORDERED => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'radiology_order_id',
        'radiology_procedure_id',
        'procedure_code',
        'procedure_name',
        'modality_snapshot',
        'body_site_snapshot',
        'clinical_note',
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
            'body_site_snapshot' => 'array',
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
    public function radiologyOrder(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrder::class);
    }

    /**
     * The catalog entry this item was ordered from.
     */
    public function radiologyProcedure(): BelongsTo
    {
        return $this->belongsTo(RadiologyProcedure::class);
    }

    /**
     * The user who cancelled this item, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Every scheduling attempt ever made for this item (Phase 5, Step 39)
     * — a cancelled appointment is never deleted, so an item may have
     * several historical rows if it was scheduled, cancelled, and
     * rescheduled from scratch.
     */
    public function radiologyAppointments(): HasMany
    {
        return $this->hasMany(RadiologyAppointment::class)->latest('id');
    }

    /**
     * The item's currently active (scheduled or confirmed) appointment, if
     * any — used to determine scheduling eligibility (Part 22) without
     * loading the full history.
     */
    public function activeRadiologyAppointment(): HasOne
    {
        return $this->hasOne(RadiologyAppointment::class)
            ->whereIn('status', RadiologyAppointment::ACTIVE_STATUSES)
            ->latestOfMany();
    }
}
