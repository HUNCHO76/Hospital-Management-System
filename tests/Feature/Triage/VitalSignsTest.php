<?php

use App\Models\VitalSign;
use App\Services\TriageService;
use App\Services\VitalSignsService;
use Illuminate\Validation\ValidationException;

test('recording vital signs calculates BMI from weight and height', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    $vitalSign = $service->record($triage, ['weight_kg' => 70, 'height_cm' => 175], requestAs(userWithRole('nurse')));

    expect((float) $vitalSign->bmi)->toBe(22.86);
});

test('BMI is null when weight or height is missing', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    $vitalSign = $service->record($triage, ['temperature_c' => 37.0], requestAs(userWithRole('nurse')));

    expect($vitalSign->bmi)->toBeNull();
});

test('recording vital signs denormalizes the clinical_encounter_id from the triage assessment', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    $vitalSign = $service->record($triage, ['pulse_bpm' => 80], requestAs(userWithRole('nurse')));

    expect($vitalSign->clinical_encounter_id)->toBe($triage->clinical_encounter_id);
});

test('recording vital signs records an audit event', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    $vitalSign = $service->record($triage, ['pulse_bpm' => 80], requestAs(userWithRole('nurse')));

    $this->assertDatabaseHas('audit_logs', ['action' => 'vital_sign_recorded', 'auditable_id' => $vitalSign->id]);
});

test('multiple vital-sign observations are all preserved as separate rows', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);
    $request = requestAs(userWithRole('nurse'));

    $service->record($triage, ['pulse_bpm' => 80], $request);
    $service->record($triage, ['pulse_bpm' => 88], $request);

    expect(VitalSign::query()->where('triage_assessment_id', $triage->id)->count())->toBe(2);
    expect(VitalSign::query()->where('triage_assessment_id', $triage->id)->pluck('pulse_bpm')->all())->toBe([80, 88]);
});

test('vital signs cannot be recorded against a completed triage assessment', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);
    $triageService = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triageService->start($triage, $request);
    $triageService->update($triage, ['triage_category' => 'urgent'], $request);
    $service->record($triage, ['pulse_bpm' => 80], $request);
    $triage = $triageService->complete($triage->fresh(), $request);

    expect(fn () => $service->record($triage, ['pulse_bpm' => 90], $request))->toThrow(ValidationException::class);
});

test('evaluate flags a value outside the configured normal range without rejecting it', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    // SpO2 82% is clinically abnormal but a technically valid percentage —
    // must be accepted and flagged, never silently modified or rejected.
    $vitalSign = $service->record($triage, ['oxygen_saturation_pct' => 82], requestAs(userWithRole('nurse')));

    $flags = $service->evaluate($vitalSign);

    expect($vitalSign->oxygen_saturation_pct)->toBe(82);
    expect($flags['oxygen_saturation_pct'])->toBe('critical');
});

test('evaluate reports normal for a value inside the configured range', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);

    $vitalSign = $service->record($triage, ['oxygen_saturation_pct' => 98], requestAs(userWithRole('nurse')));

    expect($service->evaluate($vitalSign)['oxygen_saturation_pct'])->toBe('normal');
});

test('correcting an observation preserves the original row unchanged', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);
    $request = requestAs(userWithRole('nurse'));

    $original = $service->record($triage, ['pulse_bpm' => 80], $request);

    $correction = $service->correct($original, ['pulse_bpm' => 82], 'Mis-keyed original reading', $request);

    expect($original->fresh()->pulse_bpm)->toBe(80);
    expect($correction->pulse_bpm)->toBe(82);
    expect($correction->corrects_vital_sign_id)->toBe($original->id);
    expect($correction->correction_reason)->toBe('Mis-keyed original reading');
});

test('correcting an observation records an audit event referencing the correction', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);
    $request = requestAs(userWithRole('nurse'));

    $original = $service->record($triage, ['pulse_bpm' => 80], $request);
    $correction = $service->correct($original, ['pulse_bpm' => 82], 'typo', $request);

    $this->assertDatabaseHas('audit_logs', ['action' => 'vital_sign_corrected', 'auditable_id' => $correction->id]);
});

test('an observation can only be corrected once', function () {
    $triage = openTriageAssessment();
    $service = app(VitalSignsService::class);
    $request = requestAs(userWithRole('nurse'));

    $original = $service->record($triage, ['pulse_bpm' => 80], $request);
    $service->correct($original, ['pulse_bpm' => 82], 'first correction', $request);

    expect(fn () => $service->correct($original, ['pulse_bpm' => 84], 'second correction', $request))
        ->toThrow(ValidationException::class);
});
