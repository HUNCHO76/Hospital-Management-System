<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\ObservationEscalation;
use App\Models\TriageAssessment;
use App\Models\VitalSign;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VitalSignsService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record a new vital-sign observation for a triage assessment. BMI is
     * always calculated here, never accepted from the caller (spec §7).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function record(TriageAssessment $triage, array $data, Request $request): VitalSign
    {
        if (in_array($triage->status, [TriageAssessment::STATUS_COMPLETED, TriageAssessment::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'triage_assessment_id' => "Cannot record vital signs against a triage assessment with status \"{$triage->status}\".",
            ]);
        }

        $data['bmi'] = $this->calculateBmi($data['weight_kg'] ?? null, $data['height_cm'] ?? null);

        $vitalSign = VitalSign::create([
            ...$data,
            'clinical_encounter_id' => $triage->clinical_encounter_id,
            'triage_assessment_id' => $triage->id,
            'admission_id' => $triage->clinicalEncounter?->admission_id,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
        ]);

        $this->auditLog->record('vital_sign_recorded', $vitalSign, null, $vitalSign->only(VitalSign::MEASUREMENTS), $request);

        return $vitalSign;
    }

    /**
     * Record a new vital-sign observation directly against an inpatient
     * clinical encounter (no triage assessment). Mirrors record() exactly
     * except the context is an active admission encounter rather than a
     * triage assessment row.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function recordForAdmission(Admission $admission, ClinicalEncounter $encounter, array $data, Request $request): VitalSign
    {
        if (! $admission->isActive()) {
            throw ValidationException::withMessages([
                'admission_id' => 'Vital signs can only be recorded against an active admission.',
            ]);
        }

        if ($encounter->admission_id !== $admission->id) {
            throw ValidationException::withMessages([
                'encounter_id' => 'The encounter does not belong to this admission.',
            ]);
        }

        $data['bmi'] = $this->calculateBmi($data['weight_kg'] ?? null, $data['height_cm'] ?? null);

        $vitalSign = VitalSign::create([
            ...$data,
            'clinical_encounter_id' => $encounter->id,
            'triage_assessment_id' => null,
            'admission_id' => $admission->id,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
            'measured_at' => $data['measured_at'] ?? now(),
        ]);

        $this->auditLog->record(
            'inpatient_vital_recorded',
            $vitalSign,
            null,
            array_merge($vitalSign->only(array_merge(VitalSign::MEASUREMENTS, VitalSign::NURSING_FIELDS)), ['admission_id' => $admission->id]),
            $request,
        );

        $evaluation = $this->evaluate($vitalSign);
        $criticalFlags = array_filter($evaluation, fn ($flag) => $flag === 'critical');
        if (! empty($criticalFlags) && config('vitals.escalation.auto_on_critical', true)) {
            $flagList = implode(', ', array_keys($criticalFlags));
            ObservationEscalation::create([
                'admission_id' => $admission->id,
                'patient_id' => $admission->patient_id,
                'vital_sign_id' => $vitalSign->id,
                'source' => ObservationEscalation::SOURCE_AUTO,
                'reason' => "Critical measurement detected: {$flagList}",
                'flags' => $criticalFlags,
                'raised_by' => $request->user()->id,
                'raised_at' => now(),
                'status' => ObservationEscalation::STATUS_OPEN,
            ]);
        }

        return $vitalSign;
    }

    /**
     * Correct a previously recorded observation. The original row is never
     * modified — a new row is created pointing back at it via
     * `corrects_vital_sign_id`, and the original remains exactly as
     * recorded (spec §19). Each observation can be corrected at most once;
     * a further correction is made against the correction itself.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correct(VitalSign $original, array $data, string $reason, Request $request): VitalSign
    {
        if ($original->correction()->exists()) {
            throw ValidationException::withMessages([
                'corrects_vital_sign_id' => 'This observation has already been corrected — correct the newer observation instead.',
            ]);
        }

        $data['bmi'] = $this->calculateBmi($data['weight_kg'] ?? null, $data['height_cm'] ?? null);

        $correction = VitalSign::create([
            ...$data,
            'clinical_encounter_id' => $original->clinical_encounter_id,
            'triage_assessment_id' => $original->triage_assessment_id,
            'admission_id' => $original->admission_id,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
            'measured_at' => $data['measured_at'] ?? $original->measured_at ?? now(),
            'corrects_vital_sign_id' => $original->id,
            'correction_reason' => $reason,
        ]);

        $this->auditLog->record(
            'vital_sign_corrected',
            $correction,
            $original->only(VitalSign::MEASUREMENTS),
            $correction->only(VitalSign::MEASUREMENTS),
            $request,
        );

        return $correction;
    }

    /**
     * Calculate BMI (kg/m²) from weight and height, or null when either is
     * missing.
     */
    public function calculateBmi(mixed $weightKg, mixed $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || (float) $heightCm <= 0) {
            return null;
        }

        $heightM = (float) $heightCm / 100;

        return round((float) $weightKg / ($heightM ** 2), 2);
    }

    /**
     * Evaluate a vital sign's measurements against the configured reference
     * ranges (config/vitals.php), returning a flag per present measurement.
     * This is a display aid only — never a diagnosis, and never used to
     * reject a technically valid value.
     *
     * @return array<string, 'low'|'high'|'critical'|'normal'>
     */
    public function evaluate(VitalSign $vitalSign): array
    {
        $flags = [];

        foreach (VitalSign::MEASUREMENTS as $measurement) {
            $value = $vitalSign->{$measurement};

            if ($value === null) {
                continue;
            }

            $range = config("vitals.ranges.{$measurement}");

            if (! $range || ! array_key_exists('normal_low', $range)) {
                continue;
            }

            $flags[$measurement] = $this->flagFor((float) $value, $range);
        }

        return $flags;
    }

    /**
     * @param  array{normal_low: float, normal_high: float, critical_low?: float, critical_high?: float}  $range
     * @return 'low'|'high'|'critical'|'normal'
     */
    protected function flagFor(float $value, array $range): string
    {
        if (isset($range['critical_low']) && $value <= $range['critical_low']) {
            return 'critical';
        }

        if (isset($range['critical_high']) && $value >= $range['critical_high']) {
            return 'critical';
        }

        if ($value < $range['normal_low']) {
            return 'low';
        }

        if ($value > $range['normal_high']) {
            return 'high';
        }

        return 'normal';
    }
}
