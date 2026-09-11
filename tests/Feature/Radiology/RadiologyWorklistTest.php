<?php

use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;

test('a scheduled appointment appears on the worklist with patient, procedure, priority, and resource visible', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $response = $this->actingAs($scheduler)->get(route('radiology-worklist.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('radiology/worklist/index')
        ->where('appointments.data.0.id', $appointment->id)
        ->where('appointments.data.0.resource_name', 'X-Ray Room 1')
        ->where('appointments.data.0.patient.id', $ctx['patient']->id)
        ->where('appointments.data.0.order.priority', $ctx['order']->priority)
        ->where('appointments.data.0.item.procedure_name', $ctx['item']->procedure_name));
});

test('a cancelled appointment does not appear on the default worklist but does under an explicit cancelled filter', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    app(RadiologySchedulingService::class)->cancel($appointment, 'Patient cancelled', requestAs($scheduler));

    $default = $this->actingAs($scheduler)->get(route('radiology-worklist.index'));
    $default->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    $withFilter = $this->actingAs($scheduler)->get(route('radiology-worklist.index', ['status' => 'cancelled']));
    $withFilter->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

test('the worklist can be filtered by modality, priority, and resource', function () {
    $ctxXray = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $ctxCt = radiologyOrderReadyForScheduling(['modality' => 'ct']);
    $scheduler = userWithRole('radiology-technician');
    $service = app(RadiologySchedulingService::class);

    $xrayAppointment = $service->schedule($ctxXray['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $service->schedule($ctxCt['item'], [
        'resource_name' => 'CT Scanner 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0),
        'scheduled_end' => now()->addDay()->setTime(10, 30),
    ], requestAs($scheduler));

    $response = $this->actingAs($scheduler)->get(route('radiology-worklist.index', ['modality' => 'x_ray']));
    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.id', $xrayAppointment->id));
});

test('STAT and URGENT priority appointments are sorted before ROUTINE ones regardless of time', function () {
    $ctxRoutine = radiologyOrderReadyForScheduling();
    $ctxStat = diagnosisReadyEncounter();
    $scheduler = userWithRole('radiology-technician');

    $statProcedure = activeRadiologyProcedure();
    $statOrder = app(RadiologyOrderService::class)->create($ctxStat['encounter'], [
        'procedure_ids' => [$statProcedure->id],
        'priority' => 'stat',
    ], requestAs($ctxStat['doctor']['user']));

    $service = app(RadiologySchedulingService::class);

    // Routine booked earlier in the day than STAT, to prove priority (not
    // time) drives the primary sort.
    $service->schedule($ctxRoutine['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(8, 0),
        'scheduled_end' => now()->addDay()->setTime(8, 30),
    ], requestAs($scheduler));

    $service->schedule($statOrder->items->sole(), [
        'resource_name' => 'X-Ray Room 2',
        'scheduled_start' => now()->addDay()->setTime(12, 0),
        'scheduled_end' => now()->addDay()->setTime(12, 30),
    ], requestAs($scheduler));

    $response = $this->actingAs($scheduler)->get(route('radiology-worklist.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('appointments.data.0.order.priority', 'stat')
        ->where('appointments.data.1.order.priority', 'routine'));
});
