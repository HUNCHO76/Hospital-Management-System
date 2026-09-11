<?php

use App\Models\AuditLog;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Services\RadiologyExaminationService;
use Illuminate\Validation\ValidationException;

/**
 * Core RadiologyExaminationService behavior (Phase 5, Step 41): the
 * READY -> IN_PROGRESS -> COMPLETED/ABORTED lifecycle, technical data
 * recording, resource correction, and the invalid-transition guards.
 */
test('starting an examination locks in the performer and started_at, never trusting the client', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $result = app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician));

    expect($result->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
    expect($result->performed_by)->toBe($technician->id);
    expect($result->started_at)->not->toBeNull();
});

test('starting an examination is rejected unless the appointment is READY', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    expect(fn () => app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician)))
        ->toThrow(ValidationException::class);
});

test('starting an examination is rejected when the order item is cancelled', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $ctx['item']->update(['status' => RadiologyOrderItem::STATUS_CANCELLED]);

    expect(fn () => app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician)))
        ->toThrow(ValidationException::class);
});

test('starting an examination is rejected when the radiology order is cancelled', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $ctx['order']->update(['status' => RadiologyOrder::STATUS_CANCELLED]);

    expect(fn () => app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician)))
        ->toThrow(ValidationException::class);
});

test('the resource can be corrected at start time, audited distinctly from the start itself', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx, ['resource_name' => 'X-Ray Room 1']);
    $technician = userWithRole('radiology-technician');

    $result = app(RadiologyExaminationService::class)->start($appointment, ['resource_name' => 'X-Ray Room 2'], requestAs($technician));

    expect($result->resource_name)->toBe('X-Ray Room 2');
    expect(AuditLog::query()->where('action', 'radiology_examination_resource_changed')->exists())->toBeTrue();
});

test('recording technical data only succeeds while in progress', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    expect(fn () => $service->recordTechnicalData($appointment, ['technical_notes' => 'x'], requestAs($technician)))
        ->toThrow(ValidationException::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();

    $result = $service->recordTechnicalData($appointment, [
        'technical_notes' => 'Patient cooperative.',
        'technical_details' => ['view' => 'PA', 'body_part' => 'Chest', 'laterality' => 'not_applicable'],
    ], requestAs($technician));

    expect($result->technical_notes)->toBe('Patient cooperative.');
    expect($result->technical_details)->toBe(['view' => 'PA', 'body_part' => 'Chest', 'laterality' => 'not_applicable']);
});

test('technical data keys not recognized for the appointment modality are rejected', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();

    expect(fn () => $service->recordTechnicalData($appointment, [
        'technical_details' => ['coil' => 'Head coil'],
    ], requestAs($technician)))->toThrow(ValidationException::class);
});

test('completing an examination records completed_at/completed_by and moves to COMPLETED', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'ultrasound']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();

    $result = $service->complete($appointment, [
        'technical_notes' => 'Study complete, no immediate concerns noted by technician.',
        'technical_details' => ['body_site' => 'Abdomen', 'scan_type' => 'Routine'],
    ], requestAs($technician));

    expect($result->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);
    expect($result->completed_by)->toBe($technician->id);
    expect($result->completed_at)->not->toBeNull();
});

test('an examination cannot be completed unless it is in progress', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    expect(fn () => app(RadiologyExaminationService::class)->complete($appointment, [], requestAs($technician)))
        ->toThrow(ValidationException::class);
});

test('aborting an examination requires a reason and records it', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();

    $result = $service->abort($appointment, 'equipment_problem', requestAs($technician));

    expect($result->status)->toBe(RadiologyAppointment::STATUS_ABORTED);
    expect($result->abort_reason)->toBe('equipment_problem');
    expect($result->aborted_by)->toBe($technician->id);
    expect($result->aborted_at)->not->toBeNull();
});

test('an examination cannot be aborted unless it is in progress', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    expect(fn () => app(RadiologyExaminationService::class)->abort($appointment, 'other', requestAs($technician)))
        ->toThrow(ValidationException::class);
});

test('invalid transitions are rejected: completed cannot go back to in_progress, aborted cannot become completed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $service->complete($appointment, [], requestAs($technician));
    $appointment->refresh();

    expect($appointment->canTransitionTo(RadiologyAppointment::STATUS_IN_PROGRESS))->toBeFalse();
    expect($appointment->canTransitionTo(RadiologyAppointment::STATUS_READY))->toBeFalse();

    $ctx2 = radiologyOrderReadyForScheduling();
    $appointment2 = radiologyAppointmentReadyForExamination($ctx2, ['resource_name' => 'X-Ray Room 2']);
    $service->start($appointment2, [], requestAs($technician));
    $appointment2->refresh();
    $service->abort($appointment2, 'other', requestAs($technician));
    $appointment2->refresh();

    expect($appointment2->canTransitionTo(RadiologyAppointment::STATUS_COMPLETED))->toBeFalse();
});

test('an aborted examination leaves its order item freely re-schedulable, but a completed one does not', function () {
    $ctxAborted = radiologyOrderReadyForScheduling();
    $abortedAppointment = radiologyAppointmentReadyForExamination($ctxAborted, ['resource_name' => 'X-Ray Room 1']);
    $ctxCompleted = radiologyOrderReadyForScheduling();
    $completedAppointment = radiologyAppointmentReadyForExamination($ctxCompleted, ['resource_name' => 'X-Ray Room 2']);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($abortedAppointment, [], requestAs($technician));
    $abortedAppointment->refresh();
    $service->abort($abortedAppointment, 'other', requestAs($technician));

    $service->start($completedAppointment, [], requestAs($technician));
    $completedAppointment->refresh();
    $service->complete($completedAppointment, [], requestAs($technician));

    $ctxAborted['item']->refresh();
    $ctxCompleted['item']->refresh();

    expect($ctxAborted['item']->activeRadiologyAppointment)->toBeNull();
    expect($ctxCompleted['item']->activeRadiologyAppointment)->not->toBeNull();
});
