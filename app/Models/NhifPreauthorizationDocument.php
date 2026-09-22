<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supporting document for an NHIF preauthorization request (Phase 8, Step 69).
 */
class NhifPreauthorizationDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_preauthorization_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    public function preauthorization(): BelongsTo
    {
        return $this->belongsTo(NhifPreauthorization::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
