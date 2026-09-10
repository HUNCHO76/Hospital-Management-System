<?php

namespace App\Http\Resources;

use App\Models\CarePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CarePlan
 */
class CarePlanResource extends JsonResource
{
    /**
     * Transform the care plan into its Inertia prop representation. Only
     * fields the frontend needs are exposed — never raw model internals.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'status' => $this->status,
            'goals' => $this->goals,
            'clinical_instructions' => $this->clinical_instructions,
            'monitoring_instructions' => $this->monitoring_instructions,
            'follow_up_instructions' => $this->follow_up_instructions,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => (new CarePlanItemResource($item))->resolve($request))->all(),
            ),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'corrected_at' => optional($this->corrected_at)->toIso8601String(),
            'correction_reason' => $this->correction_reason,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
