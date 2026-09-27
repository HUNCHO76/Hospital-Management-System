<?php

use App\Models\RadiologyOrderItem;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;
use Illuminate\Validation\ValidationException;

test('an unpaid radiology charge blocks appointment scheduling', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = billableRadiologyProcedure($ctx['hospital']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    // One PENDING charge captured at order time.
    $this->assertDatabaseCount('charges', 1);

    $item = $order->items->sole();
    $scheduler = userWithRole('radiology-technician');

    expect(fn () => app(RadiologySchedulingService::class)->schedule($item, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler)))
        ->toThrow(ValidationException::class, 'Payment is required');

    $this->assertDatabaseCount('radiology_appointments', 0);
});

test('a radiology procedure with no billing mapping never blocks scheduling', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseCount('charges', 0);

    $item = $order->items->sole();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($item, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    expect($appointment)->not->toBeNull();
    $this->assertDatabaseCount('radiology_appointments', 1);
});

test('an unpaid radiology charge that appears after scheduling blocks examination start', function () {
    // Use an un-mapped procedure so scheduling succeeds (no charge at that point),
    // then manually attach a PENDING charge to the order item, simulating a
    // post-scheduling manual charge or a billing-config change. The examination
    // start gate must catch it.
    $ctx = radiologyOrderReadyForScheduling();
    $this->assertDatabaseCount('charges', 0);

    // Take appointment all the way to READY status (scheduling + reception).
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $this->assertDatabaseCount('radiology_appointments', 1);

    // Now add a PENDING charge sourced from the order item.
    chargeFor($ctx['hospital'], $ctx['patient'], [
        'source_type' => RadiologyOrderItem::class,
        'source_id' => $ctx['item']->id,
    ]);
    $this->assertDatabaseCount('charges', 1);

    $technician = userWithRole('radiology-technician');

    expect(fn () => app(RadiologyExaminationService::class)->start($appointment->fresh(), [], requestAs($technician)))
        ->toThrow(ValidationException::class, 'Payment is required');
});
