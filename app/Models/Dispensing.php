<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\DispensingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single pharmacy fulfillment event against a VERIFIED PrescriptionOrder
 * (Phase 6, Step 48) — the actual dispensing of medication to a patient,
 * distinct from the clinical order (Step 46) and its verification review
 * (Step 47). One prescription may have several Dispensing records over time
 * (partial dispensing across separate visits) — each is its own immutable
 * historical event, never overwritten.
 */
class Dispensing extends Model
{
    /** @use HasFactory<DispensingFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating dispensing numbers.
     */
    public const NUMBER_PREFIX = 'DSP';

    /**
     * Opened by DispensingService::start() and either completed or
     * cancelled — only one IN_PROGRESS dispensing may exist per
     * PrescriptionOrder at a time (the mechanism that prevents two
     * pharmacists from both dispensing the same remaining quantity, spec
     * §19), enforced by row-locking the order in start().
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * DispensingService — never trust a status change supplied directly by
     * the frontend.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'prescription_order_id',
        'dispensing_number',
        'status',
        'dispensed_by',
        'started_at',
        'completed_at',
        'notes',
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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $dispensing): void {
            if (empty($dispensing->dispensing_number)) {
                $dispensing->dispensing_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'dispensing_number');
            }
        });
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
     * The prescription order this dispensing event fulfills.
     */
    public function prescriptionOrder(): BelongsTo
    {
        return $this->belongsTo(PrescriptionOrder::class);
    }

    /**
     * The medications actually recorded as dispensed (or unavailable) in
     * this event.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DispensingItem::class)->orderBy('id');
    }

    /**
     * The pharmacy user who performed this dispensing event.
     */
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    /**
     * The user who cancelled this dispensing event, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
