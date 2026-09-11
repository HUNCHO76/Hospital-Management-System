<?php

use App\Models\RadiologyAppointment;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;

/**
 * The full Step 40 pathway (Part 40): doctor creates an order -> scheduler
 * schedules it -> reception receives the patient -> identity/order
 * verification -> preparation where required -> READY -> visible for a
 * future examination step. Covers both a no-preparation procedure (Chest
 * X-Ray) and a preparation-required one (Abdominal Ultrasound), plus a
 * wrong-status failure case.
 */
test('a procedure without preparation goes from arrival straight to ready', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-RECEP', 'name' => 'Chest X-Ray (Reception E2E)', 'preparation_type' => 'none']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);

    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
    expect($appointment->fresh()->verified_at)->not->toBeNull();

    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))->assertRedirect();

    $ready = $appointment->fresh();
    expect($ready->status)->toBe(RadiologyAppointment::STATUS_READY);
    expect($ready->ready_at)->not->toBeNull();
    expect($ready->ready_by)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_patient_arrived', 'auditable_id' => $appointment->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_patient_verified', 'auditable_id' => $appointment->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_order_verified', 'auditable_id' => $appointment->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_patient_marked_ready', 'auditable_id' => $appointment->id]);
});

test('a procedure requiring preparation must complete preparation before becoming ready', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'US-ABD-RECEP', 'name' => 'Abdominal Ultrasound (Reception E2E)', 'preparation_type' => 'fasting']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'Ultrasound Room 1',
        'scheduled_start' => now()->addDay()->setTime(10, 0),
        'scheduled_end' => now()->addDay()->setTime(10, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointment));
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment));

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_PENDING);

    // Cannot be marked ready yet.
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))
        ->assertSessionHasErrors('status');

    $this->actingAs($scheduler)->post(route('radiology-reception.start-preparation', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_IN_PROGRESS);

    $this->actingAs($scheduler)->post(route('radiology-reception.complete-preparation', $appointment), [
        'instructions_given' => true,
        'completed' => true,
        'notes' => 'Patient fasted 8 hours as instructed',
    ])->assertRedirect();

    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))->assertRedirect();

    $ready = $appointment->fresh();
    expect($ready->status)->toBe(RadiologyAppointment::STATUS_READY);
    expect($ready->preparation_completed_at)->not->toBeNull();
});

test('a multi-procedure order does not mark both procedures ready just because one is', function () {
    $ctx = diagnosisReadyEncounter();
    $chestXray = activeRadiologyProcedure(['code' => 'XR-CHEST-MULTI', 'preparation_type' => 'none']);
    $abdominalUs = activeRadiologyProcedure(['code' => 'US-ABD-MULTI', 'preparation_type' => 'fasting']);
    $scheduler = userWithRole('radiology-technician');

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$chestXray->id, $abdominalUs->id],
    ], requestAs($ctx['doctor']['user']));

    $itemXray = $order->items->firstWhere('radiology_procedure_id', $chestXray->id);
    $itemUs = $order->items->firstWhere('radiology_procedure_id', $abdominalUs->id);

    $schedulingService = app(RadiologySchedulingService::class);
    $appointmentXray = $schedulingService->schedule($itemXray, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));
    $appointmentUs = $schedulingService->schedule($itemUs, [
        'resource_name' => 'Ultrasound Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 30),
        'scheduled_end' => now()->addDay()->setTime(10, 0),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointmentXray));
    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointmentUs));
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointmentXray));
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointmentUs));

    // Chest X-Ray has no preparation requirement, so it can be marked ready
    // immediately.
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointmentXray))->assertRedirect();

    expect($appointmentXray->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
    // The abdominal ultrasound must NOT have been swept along with it.
    expect($appointmentUs->fresh()->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_PENDING);

    // Attempting to mark the ultrasound ready before its own preparation is
    // complete is rejected.
    $this->actingAs($scheduler)->post(route('radiology-reception.start-preparation', $appointmentUs));
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointmentUs))
        ->assertSessionHasErrors('status');

    $this->actingAs($scheduler)->post(route('radiology-reception.complete-preparation', $appointmentUs), ['completed' => true]);
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointmentUs))->assertRedirect();

    expect($appointmentUs->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
});

test('a wrong-status verification attempt fails and the patient is never marked ready', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    // The patient has not arrived yet — verification must fail.
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment))
        ->assertSessionHasErrors('status');

    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});
