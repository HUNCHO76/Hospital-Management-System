<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bedside procedure performed directly in the inpatient ward.
 */
class BedsideProcedureEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'procedure_order_id',
        'admission_id',
        'patient_id',
        'performed_at',
        'performed_by',
        'procedure_notes',
        'patient_tolerance',
        'consumables_used',
        'complications',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
        ];
    }

    public function procedureOrder(): BelongsTo
    {
        return $this->belongsTo(ProcedureOrder::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
