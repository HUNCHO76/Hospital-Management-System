<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;

/**
 * The full Step 39 pathway (Part 38): doctor creates a radiology order ->
 * ORDERED -> a scheduler opens the unscheduled-orders queue -> selects the
 * procedure -> chooses a date/time/resource -> confirms -> SCHEDULED ->
 * appears on the radiology worklist with patient/priority/procedure/
 * time/resource all visible. Then: a second scheduler's attempt to book the
 * identical resource/time is rejected; the appointment is rescheduled with
 * a reason recorded; then cancelled with a reason, dropping off the active
 * worklist while remaining visible in history.
 */
test('an ordered procedure flows through scheduling into the radiology worklist, survives a conflicting booking attempt, and can be rescheduled then cancelled', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-SCHED', 'name' => 'Chest X-Ray (Sched E2E)', 'modality' => 'x_ray']);
    $scheduler = userWithRole('radiology-technician');

    // The item starts on the unscheduled-orders queue.
    $queue = $this->actingAs($scheduler)->get(route('radiology-scheduling.index'));
    $queue->assertOk();
    $queue->assertInertia(fn ($page) => $page->where('unscheduledItems.data.0.id', $ctx['item']->id));

    // Scheduler books it.
    $start = now()->addDay()->setTime(10, 0);
    $end = now()->addDay()->setTime(10, 30);

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => $start->toDateTimeString(),
        'scheduled_end' => $end->toDateTimeString(),
    ])->assertRedirect();

    $appointment = RadiologyAppointment::query()->where('radiology_order_item_id', $ctx['item']->id)->sole();
    expect($appointment->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    // No longer on the unscheduled queue.
    $queueAfter = $this->actingAs($scheduler)->get(route('radiology-scheduling.index'));
    $queueAfter->assertInertia(fn ($page) => $page->has('unscheduledItems.data', 0));

    // Appears on the worklist with full context visible.
    $worklist = $this->actingAs($scheduler)->get(route('radiology-worklist.index'));
    $worklist->assertInertia(fn ($page) => $page
        ->where('appointments.data.0.id', $appointment->id)
        ->where('appointments.data.0.patient.id', $ctx['patient']->id)
        ->where('appointments.data.0.order.priority', $ctx['order']->priority)
        ->where('appointments.data.0.item.procedure_code', 'XR-CHEST-SCHED')
        ->where('appointments.data.0.resource_name', 'X-Ray Room 1'));

    // A second scheduler cannot book the identical resource/time.
    $secondCtx = radiologyOrderReadyForScheduling();
    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $secondCtx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => $start->toDateTimeString(),
        'scheduled_end' => $end->toDateTimeString(),
    ])->assertSessionHasErrors('resource_name');

    // Reschedule — old/new time and reason recorded.
    $newStart = now()->addDays(2)->setTime(14, 30);
    $newEnd = now()->addDays(2)->setTime(15, 0);

    $this->actingAs($scheduler)->post(route('radiology-scheduling.reschedule', $appointment), [
        'scheduled_start' => $newStart->toDateTimeString(),
        'scheduled_end' => $newEnd->toDateTimeString(),
        'reason' => 'Patient requested a later time',
    ])->assertRedirect();

    expect($appointment->fresh()->scheduled_start->equalTo($newStart))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_appointment_rescheduled', 'auditable_id' => $appointment->id]);

    // Cancel — reason required, drops off the active worklist, history
    // remains.
    $this->actingAs($scheduler)->post(route('radiology-scheduling.cancel', $appointment), [])
        ->assertSessionHasErrors('cancellation_reason');

    $this->actingAs($scheduler)->post(route('radiology-scheduling.cancel', $appointment), [
        'cancellation_reason' => 'Patient no longer requires imaging',
    ])->assertRedirect();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_CANCELLED);
    expect($ctx['order']->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);

    $activeWorklist = $this->actingAs($scheduler)->get(route('radiology-worklist.index'));
    $activeWorklist->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    $historyWorklist = $this->actingAs($scheduler)->get(route('radiology-worklist.index', ['status' => 'cancelled']));
    $historyWorklist->assertInertia(fn ($page) => $page->where('appointments.data.0.id', $appointment->id));

    // Back on the unscheduled queue, ready to be scheduled again.
    $queueFinal = $this->actingAs($scheduler)->get(route('radiology-scheduling.index'));
    $queueFinal->assertInertia(fn ($page) => $page->where('unscheduledItems.data.0.id', $ctx['item']->id));
});
