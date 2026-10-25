<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use App\Services\FinancialAuthorizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A doctor-prescribed clinical procedure or surgical operation order.
 */
class ProcedureOrder extends Model
{
    use GeneratesBusinessNumber;

    public const NUMBER_PREFIX = 'PROC';

    public const TYPE_BEDSIDE = 'bedside_procedure';

    public const TYPE_MINOR_THEATRE = 'minor_theatre';

    public const TYPE_MAJOR_THEATRE = 'major_theatre';

    public const TYPE_ENDOSCOPY = 'endoscopy';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_BEDSIDE => 'Bedside Procedure',
        self::TYPE_MINOR_THEATRE => 'Minor Operating Theatre',
        self::TYPE_MAJOR_THEATRE => 'Major Operating Theatre',
        self::TYPE_ENDOSCOPY => 'Endoscopy / Diagnostic Procedure',
        self::TYPE_OTHER => 'Other Procedure',
    ];

    public const URGENCIES = [
        'elective' => 'Elective',
        'urgent' => 'Urgent',
        'emergency' => 'Emergency',
    ];

    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_STAT = 'stat';

    public const PRIORITIES = [
        self::PRIORITY_ROUTINE,
        self::PRIORITY_URGENT,
        self::PRIORITY_STAT,
    ];

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ORDERED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const PAYMENT_MODE_CASH = 'cash';

    public const PAYMENT_MODE_NHIF = 'nhif';

    public const PAYMENT_MODE_INSURANCE = 'insurance';

    public const PAYMENT_MODE_EXEMPT = 'exempt';

    public const BILLING_STATUS_PENDING_PAYMENT = 'pending_payment';

    public const BILLING_STATUS_PAID = 'paid';

    public const BILLING_STATUS_PENDING_AUTHORIZATION = 'pending_authorization';

    public const BILLING_STATUS_AUTHORIZED = 'authorized';

    public const BILLING_STATUS_REJECTED = 'rejected';

    public const BILLING_STATUS_EXEMPTED = 'exempted';

    public const BILLING_STATUSES = [
        self::BILLING_STATUS_PENDING_PAYMENT,
        self::BILLING_STATUS_PAID,
        self::BILLING_STATUS_PENDING_AUTHORIZATION,
        self::BILLING_STATUS_AUTHORIZED,
        self::BILLING_STATUS_REJECTED,
        self::BILLING_STATUS_EXEMPTED,
    ];

    public const NHIF_APPROVAL_PENDING = 'pending';

    public const NHIF_APPROVAL_APPROVED = 'approved';

    public const NHIF_APPROVAL_REJECTED = 'rejected';

    /** @var array<string, mixed> */
    protected $attributes = [
        'procedure_type' => self::TYPE_BEDSIDE,
        'urgency' => 'elective',
        'status' => self::STATUS_ORDERED,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_number',
        'admission_id',
        'patient_id',
        'clinical_encounter_id',
        'service_id',
        'procedure_name',
        'procedure_type',
        'urgency',
        'clinical_indication',
        'prescribed_by',
        'scheduled_at',
        'status',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'payment_mode',
        'billing_status',
        'quantity',
        'unit_price',
        'total_price',
        'charge_id',
        'invoice_id',
        'nhif_item_code',
        'nhif_item_name',
        'nhif_approval_status',
        'nhif_authorization_no',
        'nhif_preauthorization_id',
        'nhif_rejection_reason',
        'is_emergency_exemption',
        'exemption_reason',
        'exempted_by',
        'performer_id',
        'performed_at',
        'findings',
        'results',
        'procedure_notes',
        'complications',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'performed_at' => 'datetime',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'is_emergency_exemption' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'order_number');
            }
            if (empty($order->total_price) && $order->unit_price > 0) {
                $order->total_price = $order->unit_price * ($order->quantity ?: 1);
            }
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_id');
    }

    public function exemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exempted_by');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function nhifPreauthorization(): BelongsTo
    {
        return $this->belongsTo(NhifPreauthorization::class);
    }

    public function surgicalCase(): HasOne
    {
        return $this->hasOne(SurgicalCase::class);
    }

    public function bedsideEvents(): HasMany
    {
        return $this->hasMany(BedsideProcedureEvent::class)->orderByDesc('performed_at');
    }

    /**
     * Check if payment has been confirmed for cash patient.
     */
    public function isPaid(): bool
    {
        if ($this->billing_status === self::BILLING_STATUS_PAID) {
            return true;
        }

        if ($this->charge_id) {
            $financialAuth = app(FinancialAuthorizationService::class);
            if ($financialAuth->statusForCharge($this->charge) === FinancialAuthorizationService::STATUS_AUTHORIZED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the order is financially or administratively authorized.
     */
    public function isAuthorized(): bool
    {
        if ($this->billing_status === self::BILLING_STATUS_AUTHORIZED) {
            return true;
        }

        if ($this->isExempted()) {
            return true;
        }

        if ($this->payment_mode === self::PAYMENT_MODE_CASH) {
            return $this->isPaid();
        }

        return false;
    }

    public function isExempted(): bool
    {
        return $this->is_emergency_exemption || $this->billing_status === self::BILLING_STATUS_EXEMPTED;
    }

    public function isRejected(): bool
    {
        return $this->billing_status === self::BILLING_STATUS_REJECTED;
    }

    public function isPendingAuthorization(): bool
    {
        return $this->billing_status === self::BILLING_STATUS_PENDING_AUTHORIZATION;
    }

    public function isPendingPayment(): bool
    {
        return $this->payment_mode === self::PAYMENT_MODE_CASH && ! $this->isPaid() && ! $this->isExempted();
    }

    /**
     * Check if the procedure can safely proceed to execution.
     */
    public function canBeExecuted(): bool
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        return $this->isAuthorized();
    }
}
