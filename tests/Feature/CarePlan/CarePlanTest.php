<?php

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Services\CarePlanService;
use Illuminate\Validation\ValidationException;

test('starting a care plan creates a draft plan tied to the encounter and consultation', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);

    $carePlan = $service->create($ctx['encounter'], ['goals' => 'Stabilize and treat'], requestAs($ctx['doctor']['user']));

    expect($carePlan->status)->toBe(CarePlan::STATUS_DRAFT);
    expect($carePlan->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($carePlan->consultation_id)->toBe($ctx['encounter']->fresh()->consultation->id);
    expect($carePlan->goals)->toBe('Stabilize and treat');
});

test('starting a care plan records an audit event', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);

    $carePlan = $service->create($ctx['encounter'], [], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'care_plan_created', 'auditable_id' => $carePlan->id]);
});

test('a second care plan for the same encounter is rejected', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $service->create($ctx['encounter'], [], $request);

    expect(fn () => $service->create($ctx['encounter'], [], $request))->toThrow(ValidationException::class);
    expect(CarePlan::query()->where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('a care plan cannot be created without a diagnosis', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(CarePlanService::class);

    expect(fn () => $service->create($ctx['encounter'], [], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
    expect(CarePlan::query()->count())->toBe(0);
});

test('a care plan cannot be created against a cancelled encounter', function () {
    $ctx = carePlanReadyEncounter();
    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);
    $service = app(CarePlanService::class);

    expect(fn () => $service->create($ctx['encounter'], [], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('a draft plan can be updated, but a completed one cannot', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $updated = $service->update($carePlan, ['goals' => 'Updated goal'], $request);
    expect($updated->goals)->toBe('Updated goal');

    $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);
    $service->activate($carePlan->fresh(), $request);
    $service->complete($carePlan->fresh(), $request);

    expect(fn () => $service->update($carePlan->fresh(), ['goals' => 'too late'], $request))->toThrow(ValidationException::class);
});

test('activation requires at least one non-cancelled item', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);

    expect(fn () => $service->activate($carePlan, $request))->toThrow(ValidationException::class);

    $item = $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);
    $service->cancelItem($item, 'not needed', $request);

    expect(fn () => $service->activate($carePlan->fresh(), $request))->toThrow(ValidationException::class);
});

test('the full draft -> active -> completed lifecycle works', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);

    $activated = $service->activate($carePlan->fresh(), $request);
    expect($activated->status)->toBe(CarePlan::STATUS_ACTIVE);
    expect($activated->started_at)->not->toBeNull();

    $completed = $service->complete($activated->fresh(), $request);
    expect($completed->status)->toBe(CarePlan::STATUS_COMPLETED);
    expect($completed->completed_at)->not->toBeNull();
    expect($completed->completed_by)->toBe($ctx['doctor']['user']->id);
});

test('draft cannot jump straight to completed', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);

    expect(fn () => $service->complete($carePlan, $request))->toThrow(ValidationException::class);
});

test('a care plan can be cancelled from draft or active', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $cancelled = $service->cancel($carePlan, 'Patient declined treatment', $request);

    expect($cancelled->status)->toBe(CarePlan::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Patient declined treatment');
});

test('a completed care plan cannot be modified, activated, or re-completed', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);
    $service->activate($carePlan->fresh(), $request);
    $carePlan = $service->complete($carePlan->fresh(), $request);

    expect(fn () => $service->update($carePlan, ['goals' => 'late edit'], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->activate($carePlan, $request))->toThrow(ValidationException::class);
    expect(fn () => $service->cancel($carePlan, 'too late', $request))->toThrow(ValidationException::class);
});

test('a completed care plan can be corrected, stamping who/when/why', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);
    $service->activate($carePlan->fresh(), $request);
    $carePlan = $service->complete($carePlan->fresh(), $request);

    $corrected = $service->correct($carePlan, ['goals' => 'Revised goal'], 'Typo in original goal', $request);

    expect($corrected->goals)->toBe('Revised goal');
    expect($corrected->corrected_by)->toBe($ctx['doctor']['user']->id);
    expect($corrected->correction_reason)->toBe('Typo in original goal');
    expect($corrected->corrected_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'care_plan_corrected', 'auditable_id' => $carePlan->id]);
});

test('an active care plan cannot be corrected', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $service->addItem($carePlan, ['type' => 'clinical_intervention', 'description' => 'Rest'], $request);
    $service->activate($carePlan->fresh(), $request);

    expect(fn () => $service->correct($carePlan->fresh(), ['goals' => 'x'], 'reason', $request))->toThrow(ValidationException::class);
});
