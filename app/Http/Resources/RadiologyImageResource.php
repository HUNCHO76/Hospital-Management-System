<?php

namespace App\Http\Resources;

use App\Models\RadiologyImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One examination image's metadata. Deliberately never includes `path` —
 * that is a private-disk storage key, not something the browser should
 * ever see; the frontend builds the viewing URL itself from
 * `radiology-images.show` (appointment id + this image's id), which
 * re-authorizes on every request.
 *
 * @mixin RadiologyImage
 */
class RadiologyImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'radiology_appointment_id' => $this->radiology_appointment_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->only(['id', 'UserName'])),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
