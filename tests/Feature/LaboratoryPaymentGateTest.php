<?php

use App\Services\LaboratoryOrderService;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('an unpaid lab charge blocks sample registration', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    // One charge is captured (PENDING) at order time — gate must block.
    $this->assertDatabaseCount('charges', 1);

    $item = $order->items->sole();
    $sampler = userWithRole('laboratory-technician');

    expect(fn () => app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => null,
    ], requestAs($sampler)))
        ->toThrow(ValidationException::class, 'Payment is required');

    $this->assertDatabaseCount('laboratory_samples', 0);
});

test('a lab test with no billing mapping never blocks sample registration', function () {
    $ctx = diagnosisReadyEncounter();
    // activeLaboratoryTest() has no billing mapping → no charge captured.
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseCount('charges', 0);

    $item = $order->items->sole();
    $sampler = userWithRole('laboratory-technician');

    $sample = app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => null,
    ], requestAs($sampler));

    expect($sample)->not->toBeNull();
    $this->assertDatabaseCount('laboratory_samples', 1);
});
