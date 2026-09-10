<?php

namespace App\Http\Resources;

use App\Models\VitalSign;
use App\Services\VitalSignsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VitalSign
 */
class VitalSignResource extends JsonResource
{
    /**
     * Transform the vital-sign observation into its Inertia prop
     * representation. `flags` is a display aid computed against
     * config/vitals.php's reference ranges — never a diagnosis, and never
     * used to reject the value server-side (that already happened at
     * validation time).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'triage_assessment_id' => $this->triage_assessment_id,
            'clinical_encounter_id' => $this->clinical_encounter_id,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->UserName,
            ] : null),
            'recorded_at' => optional($this->recorded_at)->toIso8601String(),
            'temperature_c' => $this->temperature_c === null ? null : (float) $this->temperature_c,
            'systolic_mmhg' => $this->systolic_mmhg,
            'diastolic_mmhg' => $this->diastolic_mmhg,
            'pulse_bpm' => $this->pulse_bpm,
            'respiratory_rate_bpm' => $this->respiratory_rate_bpm,
            'oxygen_saturation_pct' => $this->oxygen_saturation_pct,
            'weight_kg' => $this->weight_kg === null ? null : (float) $this->weight_kg,
            'height_cm' => $this->height_cm === null ? null : (float) $this->height_cm,
            'bmi' => $this->bmi === null ? null : (float) $this->bmi,
            'flags' => app(VitalSignsService::class)->evaluate($this->resource),
            'corrects_vital_sign_id' => $this->corrects_vital_sign_id,
            'correction_reason' => $this->correction_reason,
            // Not whenLoaded(): that helper returns null (skipping the
            // closure entirely) whenever the loaded relation's value is
            // itself null — which is exactly the common case here (most
            // observations have no correction) — so it can never resolve to
            // the `false` this field needs. relationLoaded() is checked
            // directly instead.
            'is_corrected' => $this->relationLoaded('correction') ? $this->correction !== null : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
