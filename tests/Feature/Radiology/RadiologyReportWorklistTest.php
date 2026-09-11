<?php

use App\Services\RadiologyReportService;

test('a completed examination with no report appears on the worklist as not started', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('radiology/results/index')
        ->where('appointments.data.0.id', $appointment->id)
        ->where('appointments.data.0.report', null)
        ->where('appointments.data.0.patient.id', $ctx['patient']->id)
        ->where('appointments.data.0.order.priority', $ctx['order']->priority));
});

test('a draft report appears on the worklist with its status and author', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('appointments.data.0.report.status', 'draft')
        ->where('appointments.data.0.report.reporting_practitioner.id', $radiologist['practitioner']->id));
});

test('a submitted report appears on the worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);
    $report = $service->createDraft($appointment, ['findings' => 'x', 'impression' => 'y'], requestAs($radiologist['user']));
    $service->submit($report, requestAs($radiologist['user']));

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index', ['report_status' => 'submitted']));

    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.report.status', 'submitted'));
});

test('the report_status=none filter shows only unreported completed examinations', function () {
    $ctxReported = radiologyOrderReadyForScheduling();
    $ctxUnreported = radiologyOrderReadyForScheduling();
    $reportedAppointment = radiologyExaminationCompleted($ctxReported, ['resource_name' => 'X-Ray Room 1']);
    $unreportedAppointment = radiologyExaminationCompleted($ctxUnreported, ['resource_name' => 'X-Ray Room 2']);
    $radiologist = radiologistWithPractitioner($ctxReported['hospital']);
    app(RadiologyReportService::class)->createDraft($reportedAppointment, [], requestAs($radiologist['user']));

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index', ['report_status' => 'none']));

    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.id', $unreportedAppointment->id));
});

test('a non-completed examination never appears on the reporting worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    radiologyAppointmentReadyForExamination($ctx);

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index'));

    $response->assertInertia(fn ($page) => $page->has('appointments.data', 0));
});

test('the worklist can be filtered by modality and priority', function () {
    $ctxXray = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $ctxCt = radiologyOrderReadyForScheduling(['modality' => 'ct']);
    $xrayAppointment = radiologyExaminationCompleted($ctxXray, ['resource_name' => 'X-Ray Room 1']);
    radiologyExaminationCompleted($ctxCt, ['resource_name' => 'CT Scanner 1']);
    $radiologist = radiologistWithPractitioner($ctxXray['hospital']);

    $response = $this->actingAs($radiologist['user'])->get(route('radiology-results.index', ['modality' => 'x_ray']));

    $response->assertInertia(fn ($page) => $page
        ->has('appointments.data', 1)
        ->where('appointments.data.0.id', $xrayAppointment->id));
});
