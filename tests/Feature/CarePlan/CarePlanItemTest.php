<?php

use App\Models\CarePlanItem;
use App\Models\Department;
use App\Services\CarePlanService;
use Illuminate\Validation\ValidationException;

test('an item can be added to a draft plan and addresses the encounter diagnosis', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, [
        'type' => 'clinical_intervention',
        'description' => 'Encourage fluids',
        'encounter_diagnosis_id' => $ctx['diagnosis']->id,
    ], $request);

    expect($item->status)->toBe(CarePlanItem::STATUS_PLANNED);
    expect($item->care_plan_id)->toBe($carePlan->id);
    expect($item->encounter_diagnosis_id)->toBe($ctx['diagnosis']->id);
});

test('adding an item records an audit event', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'monitoring', 'description' => 'Check temperature every 4 hours'], $request);

    $this->assertDatabaseHas('audit_logs', ['action' => 'treatment_item_created', 'auditable_id' => $item->id]);
});

test('a diagnosis reference from another encounter is rejected', function () {
    $ctxA = carePlanReadyEncounter();
    $ctxB = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctxA['doctor']['user']);

    $carePlan = $service->create($ctxA['encounter'], [], $request);

    expect(fn () => $service->addItem($carePlan, [
        'type' => 'monitoring',
        'description' => 'x',
        'encounter_diagnosis_id' => $ctxB['diagnosis']->id,
    ], $request))->toThrow(ValidationException::class);
});

test('items cannot be added to an encounter without a diagnosis via the referral fields path', function () {
    $ctx = carePlanReadyEncounter();
    $department = Department::factory()->create(['hospital_id' => $ctx['hospital']->id]);
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, [
        'type' => 'referral',
        'description' => 'Refer to specialist',
        'referred_to_department_id' => $department->id,
    ], $request);

    expect($item->referred_to_department_id)->toBe($department->id);
});

test('a medication item creates a prescription and can be cancelled together', function () {
    $ctx = carePlanReadyEncounter();
    $medication = activeMedication();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, [
        'type' => 'medication',
        'description' => 'Paracetamol for pain',
        'prescription' => [
            'medication_id' => $medication->id,
            'strength' => '500mg',
            'dose' => '1 tablet',
            'route' => 'oral',
            'frequency' => 'three times daily',
            'duration' => '3 days',
            'quantity' => '9 tablets',
        ],
    ], $request);

    expect($item->prescription)->not->toBeNull();
    expect($item->prescription->medication_id)->toBe($medication->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_created']);

    $service->cancelItem($item, 'Adverse reaction', $request);

    expect($item->fresh()->status)->toBe(CarePlanItem::STATUS_CANCELLED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_cancelled']);
});

test('a planned item can be updated, but an active one cannot', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'monitoring', 'description' => 'Check BP'], $request);

    $updated = $service->updateItem($item, ['description' => 'Check BP hourly'], $request);
    expect($updated->description)->toBe('Check BP hourly');

    $service->changeItemStatus($item->fresh(), CarePlanItem::STATUS_ACTIVE, $request);

    expect(fn () => $service->updateItem($item->fresh(), ['description' => 'too late'], $request))->toThrow(ValidationException::class);
});

test('the full planned -> active -> completed lifecycle works', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'Wound dressing'], $request);

    $active = $service->changeItemStatus($item, CarePlanItem::STATUS_ACTIVE, $request);
    expect($active->status)->toBe(CarePlanItem::STATUS_ACTIVE);

    $completed = $service->changeItemStatus($active->fresh(), CarePlanItem::STATUS_COMPLETED, $request);
    expect($completed->status)->toBe(CarePlanItem::STATUS_COMPLETED);
});

test('planned cannot jump straight to completed', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'x'], $request);

    expect(fn () => $service->changeItemStatus($item, CarePlanItem::STATUS_COMPLETED, $request))->toThrow(ValidationException::class);
});

test('cancellation is rejected through changeItemStatus and must use the dedicated cancel action', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'x'], $request);

    expect(fn () => $service->changeItemStatus($item, CarePlanItem::STATUS_CANCELLED, $request))->toThrow(ValidationException::class);

    $cancelled = $service->cancelItem($item, 'No longer needed', $request);
    expect($cancelled->status)->toBe(CarePlanItem::STATUS_CANCELLED);
});

test('only a completed item can be corrected, and prescription fields are never touched by correction', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'Wound dressing'], $request);

    expect(fn () => $service->correctItem($item, ['description' => 'x'], 'reason', $request))->toThrow(ValidationException::class);

    $service->changeItemStatus($item->fresh(), CarePlanItem::STATUS_ACTIVE, $request);
    $service->changeItemStatus($item->fresh(), CarePlanItem::STATUS_COMPLETED, $request);

    $corrected = $service->correctItem($item->fresh(), ['description' => 'Wound dressing changed'], 'Typo', $request);
    expect($corrected->description)->toBe('Wound dressing changed');

    $this->assertDatabaseHas('audit_logs', ['action' => 'treatment_item_corrected', 'auditable_id' => $item->id]);
});

test('items cannot be added once the encounter is cancelled via a completed care plan', function () {
    $ctx = carePlanReadyEncounter();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'x'], $request);
    $service->activate($carePlan->fresh(), $request);
    $service->complete($carePlan->fresh(), $request);

    expect(fn () => $service->addItem($carePlan->fresh(), ['type' => 'monitoring', 'description' => 'y'], $request))
        ->toThrow(ValidationException::class);
});
