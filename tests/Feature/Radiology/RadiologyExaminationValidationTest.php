<?php

use App\Models\RadiologyAppointment;
use App\Services\RadiologyExaminationService;
use Illuminate\Validation\ValidationException;

test('aborting without a reason is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $this->actingAs($technician)->post(route('radiology-examinations.abort', $appointment), [])
        ->assertSessionHasErrors('reason');

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
});

test('aborting with an unrecognized reason is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $this->actingAs($technician)->post(route('radiology-examinations.abort', $appointment), [
        'reason' => 'not_a_real_reason',
    ])->assertSessionHasErrors('reason');
});

test('technical_notes exceeding the length limit is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $this->actingAs($technician)->put(route('radiology-examinations.update', $appointment), [
        'technical_notes' => str_repeat('a', 4001),
    ])->assertSessionHasErrors('technical_notes');
});

test('a technical_details key not valid for the modality is rejected server-side', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();

    expect(fn () => $service->recordTechnicalData($appointment, [
        'technical_details' => ['protocol' => 'Fast spin echo'],
    ], requestAs($technician)))->toThrow(ValidationException::class);
});

test('an invalid status transition throws a validation exception with a status message', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    try {
        $service->complete($appointment, [], requestAs($technician));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('status');
    }
});

test('recording technical data on a completed examination is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $service->complete($appointment, [], requestAs($technician));
    $appointment->refresh();

    expect(fn () => $service->recordTechnicalData($appointment, ['technical_notes' => 'too late'], requestAs($technician)))
        ->toThrow(ValidationException::class);
});
