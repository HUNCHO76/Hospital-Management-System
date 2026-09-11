<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Services\RadiologyOrderService;
use App\Services\RadiologyReceptionService;
use App\Services\RadiologySchedulingService;
use Illuminate\Validation\ValidationException;

test('a scheduled patient can be marked arrived, recording who and when', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $arrived = app(RadiologyReceptionService::class)->markArrived($appointment, requestAs($technician));

    expect($arrived->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
    expect($arrived->arrived_at)->not->toBeNull();
    expect($arrived->arrived_by)->toBe($technician->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_patient_arrived', 'auditable_id' => $appointment->id]);
});

test('verifying a procedure with no preparation requirement keeps the appointment at ARRIVED, ready to be marked ready directly', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $verified = $service->verify($appointment->fresh(), requestAs($technician));

    expect($verified->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
    expect($verified->verified_at)->not->toBeNull();
    expect($service->readinessBlockers($verified))->toBe([]);

    $ready = $service->markReady($verified, requestAs($technician));
    expect($ready->status)->toBe(RadiologyAppointment::STATUS_READY);
    expect($ready->ready_at)->not->toBeNull();
    expect($ready->ready_by)->toBe($technician->id);
});

test('verifying a procedure that requires preparation moves the appointment to PREPARATION_PENDING', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $verified = $service->verify($appointment->fresh(), requestAs($technician));

    expect($verified->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_PENDING);
});

test('a patient cannot be marked ready before preparation is completed or waived', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));
    $service->startPreparation($appointment->fresh(), requestAs($technician));

    expect($service->readinessBlockers($appointment->fresh()))->not->toBe([]);
    expect(fn () => $service->markReady($appointment->fresh(), requestAs($technician)))->toThrow(ValidationException::class);
});

test('completing preparation satisfies the readiness gate', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));
    $service->startPreparation($appointment->fresh(), requestAs($technician));
    $service->completePreparation($appointment->fresh(), ['instructions_given' => true, 'completed' => true, 'notes' => 'Fasted since 6am'], requestAs($technician));

    $fresh = $appointment->fresh();
    expect($fresh->preparation_completed_at)->not->toBeNull();
    expect($fresh->instructions_given_at)->not->toBeNull();
    expect($service->readinessBlockers($fresh))->toBe([]);

    $ready = $service->markReady($fresh, requestAs($technician));
    expect($ready->status)->toBe(RadiologyAppointment::STATUS_READY);
});

test('waiving preparation also satisfies the readiness gate', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));
    $service->waivePreparation($appointment->fresh(), 'Patient already fasted for an earlier procedure', requestAs($technician));

    $fresh = $appointment->fresh();
    expect($fresh->preparation_waived_at)->not->toBeNull();
    expect($service->readinessBlockers($fresh))->toBe([]);
});

test('a flagged MRI safety screening blocks readiness until cleared', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'mri_safety_screening', 'modality' => 'mri']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));
    $service->startPreparation($appointment->fresh(), requestAs($technician));
    $service->completePreparation($appointment->fresh(), ['completed' => true], requestAs($technician));
    $service->recordScreening($appointment->fresh(), true, 'Patient reports a pacemaker', requestAs($technician));

    $fresh = $appointment->fresh();
    expect($fresh->mri_screening_flagged)->toBeTrue();
    expect($service->readinessBlockers($fresh))->not->toBe([]);
    expect(fn () => $service->markReady($fresh, requestAs($technician)))->toThrow(ValidationException::class);

    // Clearing the flag with a fresh, unflagged screening record unblocks it.
    $service->recordScreening($fresh, false, 'Cleared by radiologist', requestAs($technician));
    expect($service->readinessBlockers($fresh->fresh()))->toBe([]);
});

test('a required contrast review is a precondition for readiness', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none', 'contrast_requirement' => 'required']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));

    expect($service->readinessBlockers($appointment->fresh()))->not->toBe([]);

    $service->reviewContrast($appointment->fresh(), requestAs($technician));
    expect($service->readinessBlockers($appointment->fresh()))->toBe([]);
});

test('pending or declined consent blocks readiness; obtained or not-required does not', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->verify($appointment->fresh(), requestAs($technician));

    $service->recordConsent($appointment->fresh(), 'pending', requestAs($technician));
    expect($service->readinessBlockers($appointment->fresh()))->not->toBe([]);

    $service->recordConsent($appointment->fresh(), 'declined', requestAs($technician));
    expect($service->readinessBlockers($appointment->fresh()))->not->toBe([]);

    $service->recordConsent($appointment->fresh(), 'obtained', requestAs($technician));
    expect($service->readinessBlockers($appointment->fresh()))->toBe([]);
});

test('a patient can be marked not ready with a reason, and the order is never cancelled', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));

    $notReady = $service->markNotReady($appointment->fresh(), 'preparation_incomplete', requestAs($technician));

    expect($notReady->status)->toBe(RadiologyAppointment::STATUS_NOT_READY);
    expect($notReady->not_ready_reason)->toBe('preparation_incomplete');
    expect($ctx['order']->fresh()->status)->not->toBe(RadiologyOrder::STATUS_CANCELLED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_patient_marked_not_ready', 'auditable_id' => $appointment->id]);
});

test('a not-ready patient can resume back into the active workflow', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));
    $service->markNotReady($appointment->fresh(), 'identity_issue', requestAs($technician));

    $resumed = $service->resume($appointment->fresh(), requestAs($technician));

    expect($resumed->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
    expect($resumed->not_ready_reason)->toBeNull();
});

test('a patient cannot be marked ready before identity and order verification', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyReceptionService::class);

    $service->markArrived($appointment, requestAs($technician));

    expect($service->readinessBlockers($appointment->fresh()))->not->toBe([]);
    expect(fn () => $service->markReady($appointment->fresh(), requestAs($technician)))->toThrow(ValidationException::class);
});

test('two procedures on the same order progress through reception independently', function () {
    $ctx = diagnosisReadyEncounter();
    $procedureA = activeRadiologyProcedure(['preparation_type' => 'none']);
    $procedureB = activeRadiologyProcedure(['preparation_type' => 'fasting']);
    $scheduler = userWithRole('radiology-technician');
    $technician = $scheduler;

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
    ], requestAs($ctx['doctor']['user']));

    $itemA = $order->items->firstWhere('radiology_procedure_id', $procedureA->id);
    $itemB = $order->items->firstWhere('radiology_procedure_id', $procedureB->id);

    $schedulingService = app(RadiologySchedulingService::class);
    $appointmentA = $schedulingService->schedule($itemA, [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));
    $appointmentB = $schedulingService->schedule($itemB, [
        'resource_name' => 'Ultrasound Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 30),
        'scheduled_end' => now()->addDay()->setTime(10, 0),
    ], requestAs($scheduler));

    $receptionService = app(RadiologyReceptionService::class);
    $receptionService->markArrived($appointmentA, requestAs($technician));
    $receptionService->markArrived($appointmentB, requestAs($technician));

    $verifiedA = $receptionService->verify($appointmentA->fresh(), requestAs($technician));
    $verifiedB = $receptionService->verify($appointmentB->fresh(), requestAs($technician));

    // A (no prep) is immediately ready-eligible; B (fasting) is not.
    expect($verifiedA->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
    expect($verifiedB->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_PENDING);

    $readyA = $receptionService->markReady($verifiedA, requestAs($technician));
    expect($readyA->status)->toBe(RadiologyAppointment::STATUS_READY);

    // B must not have been incorrectly marked ready alongside A.
    expect($appointmentB->fresh()->status)->toBe(RadiologyAppointment::STATUS_PREPARATION_PENDING);
});
