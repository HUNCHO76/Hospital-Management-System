<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A formal financial document grouping a patient account's eligible Charges
 * (Phase 7, Step 55) — see the create_invoices_table migration's own
 * docblock for the full reasoning behind its shape (no separate
 * `invoice_items` table, `charges.invoice_id` IS the line-item
 * relationship; no tax/invoice-level-discount columns; shared cancellation
 * columns for both VOIDED and CANCELLED).
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating invoice numbers.
     */
    public const NUMBER_PREFIX = 'INV';

    /**
     * DRAFT: being built — charges may still be added/removed, totals are
     * never persisted (always computed live via InvoiceService::
     * currentTotal()). POSTED: a real, immutable financial document —
     * `subtotal`/`total_amount` are frozen at this transition and every
     * linked charge is locked into this invoice. VOIDED: a DRAFT invoice
     * abandoned before it ever became a financial document. CANCELLED: a
     * POSTED invoice reversed after the fact — the row itself is never
     * edited or deleted (spec §13/§30), only its status changes, with a
     * mandatory reason; its charges are released (`invoice_id` cleared) so
     * a corrected invoice can include them (spec §13's own "Void/Cancel ->
     * Corrected Charges -> New Invoice"). No PARTIALLY_PAID/PAID states —
     * spec §22's own explicit "Step 57 owns payment states."
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
        self::STATUS_VOIDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The single source of truth for valid status transitions. Enforced by
     * InvoiceService — never trust a status change supplied directly by the
     * frontend. Mirrors Charge::TRANSITIONS' own shape exactly (DRAFT here
     * plays the role Charge's PENDING plays).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_POSTED, self::STATUS_VOIDED],
        self::STATUS_POSTED => [self::STATUS_CANCELLED],
        self::STATUS_VOIDED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'invoice_number',
        'hospital_id',
        'patient_id',
        'patient_account_id',
        'status',
        'invoice_date',
        'subtotal',
        'total_amount',
        'currency',
        'notes',
        'posted_at',
        'created_by',
        'updated_by',
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
            'invoice_date' => 'date',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'invoice_number');
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
     * Whether this invoice is still open for charge selection.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * The hospital this invoice was raised at.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * The patient this invoice was raised against.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * The account this invoice belongs to.
     */
    public function patientAccount(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class);
    }

    /**
     * Every charge currently linked to this invoice — its line items.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class)->orderBy('charged_at');
    }

    /**
     * Every insurance allocation calculated for this invoice's charges
     * (Phase 7, Step 56) — a denormalized `invoice_id` on
     * ChargeInsuranceAllocation (rather than joining through `charges`)
     * purely so this can be eager-loaded in one query, mirroring how
     * `Charge` itself denormalizes `hospital_id`/`patient_id` off its
     * account.
     */
    public function insuranceAllocations(): HasMany
    {
        return $this->hasMany(ChargeInsuranceAllocation::class);
    }

    /**
     * Every payment recorded directly against this invoice (Phase 7, Step
     * 57) — this step's own "one invoice per payment" boundary (see
     * Payment's own class docblock).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Every billing adjustment posted against this invoice (Phase 7, Step
     * 58) — credits reduce the outstanding; debits increase it.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BillingAdjustment::class);
    }

    /**
     * Every payment allocation posted against this invoice — the
     * authoritative "how much has actually been paid" ledger
     * (PaymentService::calculateOutstanding() sums this, never
     * `payments()` directly, so a future multi-invoice payment is already
     * accounted for correctly without this relation changing shape).
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The user who created this invoice.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who last updated this invoice, if any.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The user who voided or cancelled this invoice, if any.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
