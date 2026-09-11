<?php

use App\Models\RadiologyAppointment;
use App\Services\RadiologySchedulingService;

test('a missing resource name is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('resource_name');

    $this->assertDatabaseCount('radiology_appointments', 0);
});

test('a missing order item id is rejected over HTTP', function () {
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('radiology_order_item_id');
});

test('a non-existent order item id is rejected over HTTP', function () {
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => 999999,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('radiology_order_item_id');
});

test('a past start time is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->subDay()->toDateTimeString(),
        'scheduled_end' => now()->subDay()->addMinutes(30)->toDateTimeString(),
    ])->assertSessionHasErrors('scheduled_start');
});

test('an end time before the start time is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
    ])->assertSessionHasErrors('scheduled_end');
});

test('a conflicting slot is rejected over HTTP with a clear field error', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    app(RadiologySchedulingService::class)->schedule($ctxA['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctxB['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('resource_name');

    $this->assertDatabaseCount('radiology_appointments', 1);
});

test('a cancelled radiology order\'s item cannot be scheduled over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($ctx['doctor']['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$ctx['encounter'], $ctx['order']]),
        ['cancellation_reason' => 'No longer needed'],
    )->assertRedirect();

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('radiology_order_item_id');
});

test('a missing reschedule reason is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-scheduling.reschedule', $appointment), [
        'scheduled_start' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDays(2)->setTime(9, 30)->toDateTimeString(),
    ])->assertSessionHasErrors('reason');
});

test('a missing cancellation reason is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-scheduling.cancel', $appointment), [])
        ->assertSessionHasErrors('cancellation_reason');

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});
