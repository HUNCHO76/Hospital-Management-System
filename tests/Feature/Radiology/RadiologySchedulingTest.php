<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;
use Illuminate\Validation\ValidationException;

test('scheduling an ordered item creates an active appointment and audits it', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $request = requestAs($ctx['doctor']['user']);

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0),
        'scheduled_end' => now()->addDay()->setTime(10, 30),
    ], $request);

    expect($appointment->radiology_order_item_id)->toBe($ctx['item']->id);
    expect($appointment->modality)->toBe('x_ray');
    expect($appointment->resource_name)->toBe('X-Ray Room 1');
    expect($appointment->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_appointment_scheduled', 'auditable_id' => $appointment->id]);
});

test('scheduling every item on an order rolls the order up to SCHEDULED, and reverts on cancellation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $appointment = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    $service->cancel($appointment, 'Patient rescheduling', $request);

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);
});

test('an order with two items only becomes SCHEDULED once both items have an active appointment', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
    ], $request);

    $itemA = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);
    $itemB = $order->items->firstWhere('radiology_procedure_id', $procedureB->id);

    $service = app(RadiologySchedulingService::class);

    $service->schedule($itemA, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);

    $service->schedule($itemB, [
        'resource_name' => 'Ultrasound Room 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0),
        'scheduled_end' => now()->addDay()->setTime(10, 30),
    ], $request);

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);
});

test('adding a new item to a SCHEDULED order reverts it back to ORDERED', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);

    app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    $newProcedure = activeRadiologyProcedure();
    app(RadiologyOrderService::class)->update($ctx['order']->fresh(), ['procedure_ids' => [$ctx['item']->radiology_procedure_id, $newProcedure->id]], $request);

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);
});

test('an item cannot be scheduled twice while it already has an active appointment', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    expect(fn () => $service->schedule($ctx['item']->fresh(), [
        'resource_name' => 'X-Ray Room 2',
        'scheduled_start' => now()->addDay()->setTime(11, 0),
        'scheduled_end' => now()->addDay()->setTime(11, 30),
    ], $request))->toThrow(ValidationException::class);
});

test('an item can be scheduled again after its appointment is cancelled', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $first = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    $service->cancel($first, 'Patient unavailable', $request);

    $second = $service->schedule($ctx['item']->fresh(), [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(14, 0),
        'scheduled_end' => now()->addDay()->setTime(14, 30),
    ], $request);

    expect($second->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
    expect($ctx['item']->radiologyAppointments()->count())->toBe(2);
});

test('an appointment can be confirmed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $appointment = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    $confirmed = $service->confirm($appointment, $request);

    expect($confirmed->status)->toBe(RadiologyAppointment::STATUS_CONFIRMED);
    expect($confirmed->confirmed_by)->toBe($ctx['doctor']['user']->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_appointment_confirmed', 'auditable_id' => $appointment->id]);
});

test('rescheduling records old and new time plus a reason, without losing history', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $appointment = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    $newStart = now()->addDays(2)->setTime(14, 0);
    $newEnd = now()->addDays(2)->setTime(14, 30);

    $rescheduled = $service->reschedule($appointment, $newStart, $newEnd, 'Room unavailable', $request);

    expect($rescheduled->scheduled_start->equalTo($newStart))->toBeTrue();
    expect($rescheduled->reschedule_reason)->toBe('Room unavailable');
    expect($rescheduled->rescheduled_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_appointment_rescheduled', 'auditable_id' => $appointment->id]);
});

test('marking an appointment a no-show reverts the order rollup and is audited', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $appointment = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    $service->markNoShow($appointment, $request);

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_NO_SHOW);
    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_appointment_no_show', 'auditable_id' => $appointment->id]);
});

test('cancelling an order item cascades to cancel its active appointment', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);

    app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.items.cancel', [$ctx['encounter'], $ctx['order'], $ctx['item']]),
        ['cancellation_reason' => 'No longer needed'],
    )->assertRedirect();

    $appointment = RadiologyAppointment::query()->where('radiology_order_item_id', $ctx['item']->id)->sole();
    expect($appointment->status)->toBe(RadiologyAppointment::STATUS_CANCELLED);
});

test('cancelling a whole order cascades to cancel every active appointment on it', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
    ], $request);

    $itemA = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);
    $itemB = $order->items->firstWhere('radiology_procedure_id', $procedureB->id);
    $service = app(RadiologySchedulingService::class);

    $service->schedule($itemA, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);
    $service->schedule($itemB, [
        'resource_name' => 'Ultrasound Room 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0),
        'scheduled_end' => now()->addDay()->setTime(10, 30),
    ], $request);

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$ctx['encounter'], $order]),
        ['cancellation_reason' => 'Patient discharged'],
    )->assertRedirect();

    expect(RadiologyAppointment::query()->where('status', RadiologyAppointment::STATUS_CANCELLED)->count())->toBe(2);
    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_CANCELLED);
});

test('an item on a cancelled order cannot be scheduled', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);

    app(RadiologyOrderService::class)->cancel($ctx['order'], 'No longer needed', $request);

    expect(fn () => app(RadiologySchedulingService::class)->schedule($ctx['item']->fresh(), [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request))->toThrow(ValidationException::class);
});

test('an already-cancelled order item cannot be scheduled', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
    ], $request);
    $itemA = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);

    app(RadiologyOrderService::class)->cancelItem($itemA, 'Not needed', $request);

    expect(fn () => app(RadiologySchedulingService::class)->schedule($itemA->fresh(), [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request))->toThrow(ValidationException::class);
});

test('an appointment cannot be rescheduled or cancelled twice', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $request = requestAs($ctx['doctor']['user']);
    $service = app(RadiologySchedulingService::class);

    $appointment = $service->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $request);

    $service->cancel($appointment, 'reason', $request);

    expect(fn () => $service->cancel($appointment->fresh(), 'again', $request))->toThrow(ValidationException::class);
    expect(fn () => $service->reschedule($appointment->fresh(), now()->addDays(3), now()->addDays(3)->addMinutes(30), 'x', $request))
        ->toThrow(ValidationException::class);
});
