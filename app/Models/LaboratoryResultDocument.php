<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One machine/analyzer document (PDF, JPEG, PNG) attached to a
 * LaboratoryResult. `path` is a private `local`-disk storage key — never
 * exposed to the client and never used to build a public URL; every read
 * goes through LaboratoryResultDocumentController::show(), which
 * re-authorizes on every request before streaming the file. Mirrors the
 * RadiologyImage pattern exactly.
 */
class LaboratoryResultDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_result_id',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    public function laboratoryResult(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResult::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
