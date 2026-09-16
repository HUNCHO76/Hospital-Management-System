<?php

use App\Models\RadiologyOrder;
use App\Services\RadiologySchedulingService;

/**
 * The order detail page's own `status` is a coarse rollup (Phase 5, Step
 * 39, Part 21) — SCHEDULED only ever means "every item has an active
 * appointment," never the appointment's own finer-grained status. This
 * previously left the order page showing "Scheduled" for a procedure whose
 * appointment was actually CONFIRMED (or further along), which read as
 * inconsistent with the scheduling/worklist screens. The order page now
 * additionally surfaces each item's own current appointment status.
 */
test('the order detail page shows each item\'s own appointment status alongside the order\'s rollup status', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-scheduling.confirm', $appointment))->assertRedirect();

    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    $response = $this->actingAs($scheduler)->get(route('radiology.orders.show', $ctx['order']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('radiology/show')
        ->where('order.status', 'scheduled')
        ->where('order.items.0.status', 'ordered')
        ->where('order.items.0.appointment.id', $appointment->id)
        ->where('order.items.0.appointment.status', 'confirmed'));
});

test('the radiology dashboard\'s recent orders also show each item\'s own appointment status', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-scheduling.confirm', $appointment))->assertRedirect();

    $response = $this->actingAs($scheduler)->get(route('radiology.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('radiology/index')
        ->where('recentOrders.0.id', $ctx['order']->id)
        ->where('recentOrders.0.status', 'scheduled')
        ->where('recentOrders.0.items.0.appointment.status', 'confirmed'));
});

test('an item with no appointment yet shows a null appointment on the order detail page', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $technician = userWithRole('radiology-technician');

    $response = $this->actingAs($technician)->get(route('radiology.orders.show', $ctx['order']));

    $response->assertInertia(fn ($page) => $page
        ->where('order.status', 'ordered')
        ->where('order.items.0.appointment', null));
});
