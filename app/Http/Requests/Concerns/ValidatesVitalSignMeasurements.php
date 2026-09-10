<?php

namespace App\Http\Requests\Concerns;

use App\Models\VitalSign;
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
        ];
    }

    protected function between(string $measurement): string
    {
        $range = config("vitals.ranges.{$measurement}");

        return "between:{$range['min']},{$range['max']}";
    }

    /**
     * At least one measurement must be present, and a systolic/diastolic
     * pair (when both given) must be technically consistent — neither check
     * fits a single field's rule array.
     */
    protected function withMeasurementValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasAny = collect(VitalSign::MEASUREMENTS)->contains(fn ($field) => $this->filled($field));

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
