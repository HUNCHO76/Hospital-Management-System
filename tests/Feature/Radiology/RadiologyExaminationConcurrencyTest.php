<?php

use App\Models\RadiologyAppointment;
use App\Services\RadiologyExaminationService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (Part 17/29/37): two technicians racing to
 * start the same READY appointment must never both succeed. Mirrors
 * RadiologySchedulingConcurrencyTest's own back-to-back-attempts convention
 * — start() locks the appointment row and re-checks canTransitionTo() inside
 * one transaction, so each sequential call below exercises the same guard
 * that would defend against two genuinely simultaneous requests.
 */
test('two technicians attempting to start the same appointment only let one succeed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);

    $technicianA = userWithRole('radiology-technician');
    $technicianB = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $winner = $service->start($appointment, [], requestAs($technicianA));

    expect(fn () => $service->start($appointment->fresh(), [], requestAs($technicianB)))
        ->toThrow(ValidationException::class);

    $appointment->refresh();

    expect($appointment->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
    expect($appointment->performed_by)->toBe($technicianA->id);
    expect($appointment->performed_by)->not->toBe($technicianB->id);
    expect($winner->performed_by)->toBe($technicianA->id);
});

test('three sequential start attempts on the same appointment yield exactly one winner', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);

    $technicians = [userWithRole('radiology-technician'), userWithRole('radiology-technician'), userWithRole('radiology-technician')];
    $service = app(RadiologyExaminationService::class);

    $successes = 0;
    $rejections = 0;

    foreach ($technicians as $technician) {
        try {
            $service->start($appointment->fresh(), [], requestAs($technician));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
});
