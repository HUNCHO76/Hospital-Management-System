<?php

namespace App\Models;

use Database\Factories\RadiologyImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One imaging file (X-Ray/etc.) attached to a RadiologyAppointment's
 * examination. `path` is a private-disk storage key — never exposed to the
 * client and never used to build a public URL; every read goes through
 * RadiologyImageController::show(), which re-authorizes on every request
 * before streaming the file.
 */
class RadiologyImage extends Model
{
    /** @use HasFactory<RadiologyImageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'radiology_appointment_id',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    /**
     * The examination this image belongs to.
     */
    public function radiologyAppointment(): BelongsTo
    {
        return $this->belongsTo(RadiologyAppointment::class);
    }

    /**
     * The user who uploaded this image.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
