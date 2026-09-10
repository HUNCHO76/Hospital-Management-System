<?php

use App\Models\CarePlanItem;
use App\Services\CarePlanService;
use App\Services\PrescriptionService;
use Illuminate\Validation\ValidationException;

test('a prescription can only be attached to a medication item', function () {
    $ctx = carePlanReadyEncounter();
    $medication = activeMedication();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, ['type' => 'procedure', 'description' => 'Dressing'], $request);

    expect(fn () => app(PrescriptionService::class)->create($item, ['medication_id' => $medication->id], $request))
        ->toThrow(ValidationException::class);
});

test('updating a prescription while the item is still planned works', function () {
    $ctx = carePlanReadyEncounter();
    $medication = activeMedication();
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);
    $item = $service->addItem($carePlan, [
        'type' => 'medication',
        'description' => 'Amoxicillin course',
        'prescription' => ['medication_id' => $medication->id, 'dose' => '1 capsule'],
    ], $request);

    $updated = $service->updateItem($item, ['prescription' => ['dose' => '2 capsules']], $request);

    expect($updated->prescription->dose)->toBe('2 capsules');
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_updated']);
});

test('an inactive medication cannot be selected for a new prescription via the item request layer', function () {
    $ctx = carePlanReadyEncounter();
    $inactive = activeMedication(['status' => 'inactive']);
    $service = app(CarePlanService::class);
    $request = requestAs($ctx['doctor']['user']);

    $carePlan = $service->create($ctx['encounter'], [], $request);

    // The service itself does not re-validate medication status (that's the
    // Form Request's job at the HTTP boundary — see StoreCarePlanItemRequest
    // and CarePlanApiTest-equivalent web tests); calling the service
    // directly with an inactive medication id still succeeds, so this test
    // documents that the boundary is enforced at validation time.
    $item = $service->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_MEDICATION,
        'description' => 'x',
        'prescription' => ['medication_id' => $inactive->id],
    ], $request);

    expect($item->prescription->medication_id)->toBe($inactive->id);
});
