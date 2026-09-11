<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrderItem;
use App\Services\RadiologySchedulingService;

/**
 * The full Step 41 pathway (Part 29): doctor orders a Chest X-Ray ->
 * radiology order created -> scheduled -> patient arrives -> identity/order
 * verified -> preparation completed -> READY -> examination worklist ->
 * radiographer starts the examination -> technical information recorded ->
 * examination completed -> COMPLETED (the Step 42 handoff point). Also
 * covers the READY -> start -> abort branch and the two-technicians-race
 * branch, exactly as this step's own spec requires.
 */
test('the full pathway from doctor order to a completed, findings-ready examination', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-EXAM-E2E', 'name' => 'Chest X-Ray (Examination E2E)', 'modality' => 'x_ray', 'preparation_type' => 'none']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment))->assertRedirect();
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))->assertRedirect();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);

    $technician = userWithRole('radiology-technician');

    // The worklist surfaces it.
    $this->actingAs($technician)->get(route('radiology-examinations.index'))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.id', $appointment->id)
            ->where('appointments.data.0.status', 'ready'));

    // The technician opens the detail page and starts the examination.
    $this->actingAs($technician)->get(route('radiology-examinations.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('radiology/examinations/show')
            ->where('appointment.patient.id', $ctx['patient']->id)
            ->where('appointment.order.order_number', $ctx['order']->order_number));

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $inProgress = $appointment->fresh();
    expect($inProgress->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
    expect($inProgress->performed_by)->toBe($technician->id);
    expect($inProgress->started_at)->not->toBeNull();

    // Technical information is recorded.
    $this->actingAs($technician)->put(route('radiology-examinations.update', $appointment), [
        'technical_notes' => 'PA and lateral views obtained without complication.',
        'technical_details' => ['view' => 'PA', 'body_part' => 'Chest', 'laterality' => 'not_applicable'],
    ])->assertRedirect();

    expect($appointment->fresh()->technical_details)->toBe(['view' => 'PA', 'body_part' => 'Chest', 'laterality' => 'not_applicable']);

    // The examination is completed — the Step 42 handoff point.
    $this->actingAs($technician)->post(route('radiology-examinations.complete', $appointment))->assertRedirect();

    $completed = $appointment->fresh();
    expect($completed->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);
    expect($completed->completed_by)->toBe($technician->id);
    expect($completed->completed_at)->not->toBeNull();

    // A future Step 42 would query exactly this: completed examinations
    // ready for findings, fully traceable back to patient/order/item.
    $readyForFindings = RadiologyAppointment::query()
        ->where('status', RadiologyAppointment::STATUS_COMPLETED)
        ->with('radiologyOrderItem.radiologyOrder.clinicalEncounter.patient')
        ->get();

    expect($readyForFindings)->toHaveCount(1);
    expect($readyForFindings->first()->radiologyOrderItem->radiologyOrder->clinicalEncounter->patient->id)->toBe($ctx['patient']->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_started', 'auditable_id' => $appointment->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_technical_data_recorded', 'auditable_id' => $appointment->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_completed', 'auditable_id' => $appointment->id]);
});

test('ready -> start -> abort records the reason and the order item remains re-schedulable', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);

    $this->actingAs($technician)->post(route('radiology-examinations.abort', $appointment), [
        'reason' => 'patient_refused',
    ])->assertRedirect();

    $aborted = $appointment->fresh();
    expect($aborted->status)->toBe(RadiologyAppointment::STATUS_ABORTED);
    expect($aborted->abort_reason)->toBe('patient_refused');
    expect($aborted->aborted_by)->toBe($technician->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_aborted', 'auditable_id' => $appointment->id]);

    // The item is preserved (never hard-deleted) and remains re-schedulable
    // for a fresh examination attempt.
    $ctx['item']->refresh();
    expect($ctx['item']->status)->toBe(RadiologyOrderItem::STATUS_ORDERED);
    expect($ctx['item']->activeRadiologyAppointment)->toBeNull();
});

test('ready -> technician A starts -> technician B is denied', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);

    $technicianA = userWithRole('radiology-technician');
    $technicianB = userWithRole('radiology-technician');

    $this->actingAs($technicianA)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $this->actingAs($technicianB)->post(route('radiology-examinations.start', $appointment))
        ->assertSessionHasErrors('status');

    $final = $appointment->fresh();
    expect($final->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
    expect($final->performed_by)->toBe($technicianA->id);
});
