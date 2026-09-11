<?php

use App\Services\RadiologyExaminationService;

test('a READY appointment appears on the examination worklist with patient, procedure, and priority visible', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $response = $this->actingAs($technician)->get(route('radiology-examinations.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('radiology/examinations/index')
        ->where('appointments.data.0.id', $appointment->id)
        ->where('appointments.data.0.status', 'ready')
        ->where('appointments.data.0.patient.id', $ctx['patient']->id)
        ->where('appointments.data.0.order.priority', $ctx['order']->priority)
        ->where('appointments.data.0.item.procedure_name', $ctx['item']->procedure_name));
});

test('a NOT_READY or SCHEDULED appointment does not appear on the default examination worklist', function () {
    $ctxScheduled = radiologyOrderReadyForScheduling();
    $technician = userWithRole('radiology-technician');
    scheduledAppointmentFor($ctxScheduled);

    $response = $this->actingAs($technician)->get(route('radiology-examinations.index'));

    $response->assertInertia(fn ($page) => $page->has('appointments.data', 0));
});

test('a COMPLETED examination is excluded from the default worklist but visible under an explicit status filter', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $service = app(RadiologyExaminationService::class);

    $service->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $service->complete($appointment, [], requestAs($technician));

    $default = $this->actingAs($technician)->get(route('radiology-examinations.index'));
    $default->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    $withFilter = $this->actingAs($technician)->get(route('radiology-examinations.index', ['status' => 'completed']));
    $withFilter->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.status', 'completed'));
});

test('an IN_PROGRESS examination is included in the default worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician));

    $response = $this->actingAs($technician)->get(route('radiology-examinations.index'));
    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.status', 'in_progress'));
});

test('the examination worklist can be filtered by modality and priority', function () {
    $ctxXray = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $ctxCt = radiologyOrderReadyForScheduling(['modality' => 'ct']);
    $technician = userWithRole('radiology-technician');

    $xrayAppointment = radiologyAppointmentReadyForExamination($ctxXray, ['resource_name' => 'X-Ray Room 1']);
    radiologyAppointmentReadyForExamination($ctxCt, ['resource_name' => 'CT Scanner 1']);

    $response = $this->actingAs($technician)->get(route('radiology-examinations.index', ['modality' => 'x_ray']));
    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.id', $xrayAppointment->id));
});
