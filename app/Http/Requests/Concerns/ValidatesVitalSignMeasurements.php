<?php

namespace App\Http\Requests\Concerns;

use App\Models\VitalSign;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation rules for recording or correcting a vital-sign
 * observation — used by both the store and correction requests (API and
 * web), so the technical validity bounds (config/vitals.php) only need to
 * stay in sync in one place.
 */
trait ValidatesVitalSignMeasurements
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function measurementRules(): array
    {
        return [
            'temperature_c' => ['nullable', 'numeric', $this->between('temperature_c')],
            'systolic_mmhg' => ['nullable', 'integer', $this->between('systolic_mmhg')],
            'diastolic_mmhg' => ['nullable', 'integer', $this->between('diastolic_mmhg')],
            'pulse_bpm' => ['nullable', 'integer', $this->between('pulse_bpm')],
            'respiratory_rate_bpm' => ['nullable', 'integer', $this->between('respiratory_rate_bpm')],
            'oxygen_saturation_pct' => ['nullable', 'integer', $this->between('oxygen_saturation_pct')],
            'weight_kg' => ['nullable', 'numeric', $this->between('weight_kg')],
            'height_cm' => ['nullable', 'numeric', $this->between('height_cm')],
            'measured_at' => ['nullable', 'date'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'consciousness_level' => ['nullable', 'string', Rule::in(VitalSign::CONSCIOUSNESS_LEVELS)],
            'oxygen_support' => ['nullable', 'string', Rule::in(VitalSign::OXYGEN_SUPPORT)],
            'oxygen_flow_lpm' => ['nullable', 'numeric', 'between:0,30'],
            'intake_ml' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'output_ml' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'observation_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function between(string $measurement): string
    {
        $range = config("vitals.ranges.{$measurement}");

        return "between:{$range['min']},{$range['max']}";
    }

    /**
     * At least one measurement or nursing observation must be present, and a
     * systolic/diastolic pair (when both given) must be technically consistent.
     */
    protected function withMeasurementValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowedFields = array_merge(VitalSign::MEASUREMENTS, VitalSign::NURSING_FIELDS);
            $hasAny = collect($allowedFields)->contains(fn ($field) => $this->filled($field));

            if (! $hasAny) {
                $validator->errors()->add('measurements', 'At least one vital-sign measurement must be provided.');
            }

            $systolic = $this->input('systolic_mmhg');
            $diastolic = $this->input('diastolic_mmhg');

            if ($systolic !== null && $diastolic !== null && (int) $diastolic >= (int) $systolic) {
                $validator->errors()->add('diastolic_mmhg', 'Diastolic pressure must be lower than systolic pressure.');
            }
        });
    }
}
