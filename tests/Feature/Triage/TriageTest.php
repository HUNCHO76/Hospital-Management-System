<?php

use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use App\Services\TriageService;
use App\Services\VitalSignsService;
use Illuminate\Validation\ValidationException;

test('starting triage creates a pending assessment tied to the encounter', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $user = userWithRole('nurse');

    $triage = $service->create($encounter, requestAs($user));

    expect($triage->status)->toBe(TriageAssessment::STATUS_PENDING);
    expect($triage->clinical_encounter_id)->toBe($encounter->id);
    expect($triage->assessed_by)->toBe($user->id);
});

test('starting triage records an audit event', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);

    $triage = $service->create($encounter, requestAs(userWithRole('nurse')));

    $this->assertDatabaseHas('audit_logs', ['action' => 'triage_created', 'auditable_id' => $triage->id]);
});

test('a second triage assessment for the same encounter is rejected', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $service->create($encounter, $request);

    expect(fn () => $service->create($encounter, $request))->toThrow(ValidationException::class);
    expect(TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->count())->toBe(1);
});

test('triage cannot be started against a cancelled encounter', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'status' => ClinicalEncounter::STATUS_CANCELLED,
    ]);
    $service = app(TriageService::class);

    expect(fn () => $service->create($encounter, requestAs(userWithRole('nurse'))))->toThrow(ValidationException::class);
    expect(TriageAssessment::query()->count())->toBe(0);
});

test('triage cannot be started against a completed encounter', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
        'status' => ClinicalEncounter::STATUS_COMPLETED,
    ]);
    $service = app(TriageService::class);

    expect(fn () => $service->create($encounter, requestAs(userWithRole('nurse'))))->toThrow(ValidationException::class);
    expect(TriageAssessment::query()->count())->toBe(0);
});

test('the full pending -> in_progress -> completed lifecycle works', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $started = $service->start($triage, $request);
    expect($started->status)->toBe(TriageAssessment::STATUS_IN_PROGRESS);

    $service->update($triage, ['triage_category' => 'urgent'], $request);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $request);

    $completed = $service->complete($triage->fresh(), $request);
    expect($completed->status)->toBe(TriageAssessment::STATUS_COMPLETED);
    expect($completed->completed_at)->not->toBeNull();
});

test('triage cannot be completed without a triage category', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $service->start($triage, $request);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $request);

    expect(fn () => $service->complete($triage->fresh(), $request))->toThrow(ValidationException::class);
});

test('triage cannot be completed without at least one vital-sign observation', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $service->start($triage, $request);
    $service->update($triage, ['triage_category' => 'urgent'], $request);

    expect(fn () => $service->complete($triage->fresh(), $request))->toThrow(ValidationException::class);
});

test('pending triage cannot jump straight to completed', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);

    expect(fn () => $service->complete($triage, $request))->toThrow(ValidationException::class);
});

test('triage can be cancelled from pending or in_progress', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $cancelled = $service->cancel($triage, 'Patient left', $request);

    expect($cancelled->status)->toBe(TriageAssessment::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Patient left');
});

test('a completed triage cannot be modified, started, or re-completed', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $service->start($triage, $request);
    $service->update($triage, ['triage_category' => 'urgent'], $request);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $request);
    $triage = $service->complete($triage->fresh(), $request);

    expect(fn () => $service->update($triage, ['notes' => 'late edit'], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->start($triage, $request))->toThrow(ValidationException::class);
    expect(fn () => $service->cancel($triage, 'too late', $request))->toThrow(ValidationException::class);
});

test('cancelling triage does not affect the clinical encounter status', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $service = app(TriageService::class);
    $request = requestAs(userWithRole('nurse'));

    $triage = $service->create($encounter, $request);
    $service->cancel($triage, 'no longer needed', $request);

    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);
});
