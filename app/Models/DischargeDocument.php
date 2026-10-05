<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\DischargeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated discharge document for one admission episode (Phase 9, Step 94).
 *
 * Each call to DischargeDocumentService::generate() creates a new version —
 * the previous version is superseded, never overwritten. The `data_snapshot`
 * JSON captures the full clinical and financial picture at generation time so
 * the document remains historically reproducible even if records change later.
 */
class DischargeDocument extends Model
{
    /** @use HasFactory<DischargeDocumentFactory> */
    use GeneratesBusinessNumber, HasFactory;

    public const NUMBER_PREFIX = 'DISC';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /** @var list<string> */
    protected $fillable = [
        'document_number',
        'admission_id',
        'hospital_id',
        'patient_id',
        'version',
        'status',
        'data_snapshot',
        'generated_by',
        'generated_at',
        'amendment_reason',
        'amended_by',
        'amended_at',
    ];

    protected function casts(): array
    {
        return [
            'data_snapshot' => 'array',
            'generated_at' => 'datetime',
            'amended_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    protected static function booted(): void
    {
        static::creating(function (self $doc): void {
            if (empty($doc->document_number)) {
                $doc->document_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'document_number');
            }
        });
    }
}
