<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item within an NHIF claim, one per utilization record (Phase 8, Steps 72–73).
 */
class NhifClaimItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_id',
        'nhif_utilization_id',
        'utilization_key',
        'line_no',
        'charge_id',
        'invoice_id',
        'service_id',
        'nhif_service_mapping_id',
        'nhif_preauthorization_id',
        'admission_id',
        'module',
        'description',
        'nhif_item_code',
        'nhif_item_name',
        'nhif_package_code',
        'nhif_authorization_no',
        'service_date',
        'quantity',
        'hms_unit_price',
        'hms_amount',
        'nhif_allowable_amount',
        'nhif_approved_amount',
        'nhif_responsibility_amount',
        'patient_responsibility_amount',
        'removed_at',
        'removed_by',
        'removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'hms_unit_price' => 'decimal:2',
            'hms_amount' => 'decimal:2',
            'nhif_allowable_amount' => 'decimal:2',
            'nhif_approved_amount' => 'decimal:2',
            'nhif_responsibility_amount' => 'decimal:2',
            'patient_responsibility_amount' => 'decimal:2',
            'removed_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }

    public function utilization(): BelongsTo
    {
        return $this->belongsTo(NhifUtilization::class, 'nhif_utilization_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function nhifServiceMapping(): BelongsTo
    {
        return $this->belongsTo(NhifServiceMapping::class);
    }

    public function nhifPreauthorization(): BelongsTo
    {
        return $this->belongsTo(NhifPreauthorization::class);
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
