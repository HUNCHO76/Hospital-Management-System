<?php

namespace App\Models;

use Database\Factories\ServicePriceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service's price at one hospital, for one effective-dated period (Phase
 * 7, Step 53) — see the create_service_prices_table migration's own
 * docblock for the full reasoning behind its shape.
 */
class ServicePrice extends Model
{
    /** @use HasFactory<ServicePriceFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'hospital_id',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
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
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Whether this price is currently in effect for the given date (spec
     * §18/§30) — active status, and the date falls within
     * [effective_from, effective_to] inclusive, an open effective_to
     * meaning "current, ongoing."
     */
    public function coversDate(DateTimeInterface $date): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $date = Carbon::parse($date)->startOfDay();

        if ($this->effective_from->startOfDay()->gt($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->startOfDay()->gte($date);
    }

    /**
     * The service this price applies to.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The hospital this price applies at.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The user who created this price.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who cancelled this price, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
