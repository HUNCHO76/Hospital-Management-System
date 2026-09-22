<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supporting document attached to an NHIF claim (Phase 8, Steps 72–73).
 */
class NhifClaimDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_claim_id',
        'hospital_id',
        'document_type',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'checksum',
        'uploaded_by',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(NhifClaim::class, 'nhif_claim_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
