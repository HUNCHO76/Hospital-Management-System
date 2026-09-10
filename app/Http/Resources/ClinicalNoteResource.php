<?php

namespace App\Http\Resources;

use App\Models\ClinicalNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalNote
 */
class ClinicalNoteResource extends JsonResource
{
    /**
     * Transform the clinical note into its Inertia prop representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'patient_id' => $this->patient_id,
            'note_type' => $this->note_type,
            'note_content' => $this->note_content,
            'status' => $this->status,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->UserName,
            ] : null),
            'signed_at' => optional($this->signed_at)->toIso8601String(),
            'signed_by' => $this->whenLoaded('signedBy', fn () => $this->signedBy ? [
                'id' => $this->signedBy->id,
                'name' => $this->signedBy->UserName,
            ] : null),
            'corrected_at' => optional($this->corrected_at)->toIso8601String(),
            'correction_reason' => $this->correction_reason,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'versions' => $this->whenLoaded('versions', fn () => ClinicalNoteVersionResource::collection($this->versions)->resolve($request)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
