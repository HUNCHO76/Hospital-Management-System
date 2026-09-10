<?php

namespace App\Http\Resources;

use App\Models\ClinicalNoteVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalNoteVersion
 */
class ClinicalNoteVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_number' => $this->version_number,
            'note_type' => $this->note_type,
            'note_content' => $this->note_content,
            'action' => $this->action,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->UserName,
            ] : null),
            'reason' => $this->reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
