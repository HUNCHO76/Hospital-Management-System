<?php

use App\Models\ClinicalEncounter;
use App\Services\ClinicalEncounterService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

test('creating an encounter generates a unique, prefixed encounter number and defaults to open', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect($encounter->status)->toBe(ClinicalEncounter::STATUS_OPEN);
    expect($encounter->encounter_number)->toStartWith('ENC-'.now()->year.'-');
    expect($encounter->created_by)->toBe($user->id);
});

test('encounter creation records an audit event', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clinical_encounter_created',
        'auditable_id' => $encounter->id,
    ]);
});

test('a second active encounter for the same patient is rejected', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect(fn () => $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request))->toThrow(ValidationException::class);

    expect(ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->count())->toBe(1);
});

test('a new encounter can be created once the previous one is completed', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $first = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);
    $service->start($first, $request);
    $service->complete($first, [], $request);

    $second = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'follow_up',
    ], $request);

    expect($second->status)->toBe(ClinicalEncounter::STATUS_OPEN);
    expect(ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->count())->toBe(2);
});

test('the full open -> in_progress -> completed lifecycle works and stamps timestamps', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    $started = $service->start($encounter, $request);
    expect($started->status)->toBe(ClinicalEncounter::STATUS_IN_PROGRESS);
    expect($started->started_at)->not->toBeNull();

    $completed = $service->complete($started, [], $request);
    expect($completed->status)->toBe(ClinicalEncounter::STATUS_COMPLETED);
    expect($completed->ended_at)->not->toBeNull();
    expect($completed->closed_by)->toBe($user->id);
    expect($completed->disposition)->toBe(ClinicalEncounter::DISPOSITION_COMPLETED);
});

test('completing an encounter can record an explicit disposition and notes', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);
    $service->start($encounter, $request);

    $completed = $service->complete($encounter, [
        'disposition' => ClinicalEncounter::DISPOSITION_FOLLOW_UP,
        'disposition_notes' => 'Review in 1 week',
    ], $request);

    expect($completed->disposition)->toBe(ClinicalEncounter::DISPOSITION_FOLLOW_UP);
    expect($completed->disposition_notes)->toBe('Review in 1 week');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'clinical_encounter_completed',
        'auditable_id' => $encounter->id,
    ]);
});

test('an encounter can be cancelled from open with a reason', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    $cancelled = $service->cancel($encounter, 'Patient left before service', $request);

    expect($cancelled->status)->toBe(ClinicalEncounter::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Patient left before service');
    expect($cancelled->ended_at)->not->toBeNull();
});

test('an encounter can be cancelled from in_progress', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);
    $service->start($encounter, $request);

    $cancelled = $service->cancel($encounter, 'Clinical reason', $request);

    expect($cancelled->status)->toBe(ClinicalEncounter::STATUS_CANCELLED);
});

test('a completed encounter cannot be reopened, started, or re-completed', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);
    $service->start($encounter, $request);
    $service->complete($encounter, [], $request);

    expect(fn () => $service->start($encounter, $request))->toThrow(ValidationException::class);
    expect(fn () => $service->complete($encounter, [], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->cancel($encounter, 'too late', $request))->toThrow(ValidationException::class);

    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_COMPLETED);
});

test('a cancelled encounter cannot transition anywhere', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);
    $service->cancel($encounter, 'no longer needed', $request);

    expect(fn () => $service->start($encounter, $request))->toThrow(ValidationException::class);
    expect(fn () => $service->complete($encounter, [], $request))->toThrow(ValidationException::class);
});

test('an open encounter cannot jump straight to completed', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect(fn () => $service->complete($encounter, [], $request))->toThrow(ValidationException::class);
    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);
});

test('findActiveEncounter returns null once the encounter is closed', function () {
    $service = app(ClinicalEncounterService::class);
    $ctx = clinicalEncounterContext();
    $user = userWithRole('nurse');
    $request = Request::create('/', 'POST');
    $request->setUserResolver(fn () => $user);

    $encounter = $service->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'encounter_type' => 'opd',
    ], $request);

    expect($service->findActiveEncounter($ctx['patient']->id)?->id)->toBe($encounter->id);

    $service->cancel($encounter, 'done', $request);

    expect($service->findActiveEncounter($ctx['patient']->id))->toBeNull();
});
