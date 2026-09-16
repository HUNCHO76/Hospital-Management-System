<?php

namespace App\Models;

use Database\Factories\DispensingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fulfillment of a single PrescriptionOrderItem within one Dispensing
 * event (Phase 6, Step 48). `dispensed_quantity` is this event's own
 * contribution only, never a running total — see the
 * create_dispensing_items_table migration's own docblock for why the
 * prescribed-vs-dispensed/remaining comparison is always derived by summing
 * every non-cancelled row for a given prescription order item, rather than
 * stored anywhere directly.
 */
class DispensingItem extends Model
{
    /** @use HasFactory<DispensingItemFactory> */
    use HasFactory;

    /**
     * DISPENSED: a positive quantity was actually handed to the patient this
     * event. UNAVAILABLE: the pharmacist explicitly recorded that none of
     * this medication could be dispensed this event (e.g. out of stock) —
     * folds spec §4's "UNABLE_TO_DISPENSE" into the item level rather than a
     * redundant top-level Dispensing status, since a single event can mix
     * some medications dispensed and others unavailable.
     */
    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DISPENSED,
        self::STATUS_UNAVAILABLE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'dispensing_id',
        'prescription_order_item_id',
        'dispensed_quantity',
        'status',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispensed_quantity' => 'integer',
        ];
    }

    /**
     * The dispensing event this fulfillment record belongs to.
     */
    public function dispensing(): BelongsTo
    {
        return $this->belongsTo(Dispensing::class);
    }

    /**
     * The prescription item this record fulfills.
     */
    public function prescriptionOrderItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrderItem::class);
    }
}
