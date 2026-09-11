<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Core RadiologyReportService behavior (Phase 5, Step 42): draft creation,
 * editing, submission, the required-findings/impression gate, and the
 * eligibility guard (only COMPLETED examinations may be reported on).
 */
test('a draft report can be created for a completed examination, resolving the practitioner server-side', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));

    expect($report->status)->toBe(RadiologyReport::STATUS_DRAFT);
    expect($report->radiology_appointment_id)->toBe($appointment->id);
    expect($report->reporting_practitioner_id)->toBe($radiologist['practitioner']->id);
    expect($report->report_number)->toStartWith('RAD-RPT-');
});

test('a report cannot be created for an examination that is not completed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    expect(fn () => app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user'])))
        ->toThrow(ValidationException::class);
});

test('a report cannot be created twice for the same examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->createDraft($appointment, [], requestAs($radiologist['user']));

    expect(fn () => $service->createDraft($appointment, [], requestAs($radiologist['user'])))
        ->toThrow(ValidationException::class);

    expect(RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(1);
});

test('a user with no practitioner profile cannot create a report', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $userWithoutPractitioner = userWithRole('radiologist');

    expect(fn () => app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($userWithoutPractitioner)))
        ->toThrow(ValidationException::class);
});

test('a draft can be saved, reopened, and updated', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, ['findings' => 'Initial findings.'], requestAs($radiologist['user']));

    $updated = $service->updateDraft($report, [
        'technique' => 'PA and lateral views.',
        'findings' => 'Lung fields are clear. No focal consolidation.',
        'impression' => 'No acute cardiopulmonary process.',
        'recommendations' => 'None.',
    ], requestAs($radiologist['user']));

    expect($updated->technique)->toBe('PA and lateral views.');
    expect($updated->findings)->toBe('Lung fields are clear. No focal consolidation.');
    expect($updated->impression)->toBe('No acute cardiopulmonary process.');
    expect($updated->status)->toBe(RadiologyReport::STATUS_DRAFT);
});

test('submitting a report requires findings and impression to be present', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, [], requestAs($radiologist['user']));

    try {
        $service->submit($report, requestAs($radiologist['user']));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['findings', 'impression']);
    }

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_DRAFT);
});

test('a complete draft can be submitted, recording submitted_by/submitted_at', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, [
        'findings' => 'Findings text.',
        'impression' => 'Impression text.',
    ], requestAs($radiologist['user']));

    $submitted = $service->submit($report, requestAs($radiologist['user']));

    expect($submitted->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
    expect($submitted->submitted_by)->toBe($radiologist['user']->id);
    expect($submitted->submitted_at)->not->toBeNull();
});

test('a submitted report cannot be edited or resubmitted', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, [
        'findings' => 'Findings text.',
        'impression' => 'Impression text.',
    ], requestAs($radiologist['user']));
    $service->submit($report, requestAs($radiologist['user']));
    $report->refresh();

    expect(fn () => $service->updateDraft($report, ['findings' => 'changed'], requestAs($radiologist['user'])))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->submit($report, requestAs($radiologist['user'])))
        ->toThrow(ValidationException::class);
});

test('text fields exceeding their length limits are rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    expect(fn () => app(RadiologyReportService::class)->createDraft($appointment, [
        'impression' => str_repeat('a', 4001),
    ], requestAs($radiologist['user'])))->toThrow(ValidationException::class);
});

test('RadiologyReport::TRANSITIONS implements the full Step 42-43 lifecycle', function () {
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_DRAFT])->toBe([RadiologyReport::STATUS_SUBMITTED]);
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_SUBMITTED])->toBe([
        RadiologyReport::STATUS_VERIFIED,
        RadiologyReport::STATUS_RETURNED,
    ]);
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_RETURNED])->toBe([RadiologyReport::STATUS_DRAFT]);
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_VERIFIED])->toBe([]);
});

test('a report remains traceable to patient, encounter, order, item, procedure, and examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));

    $report->load('radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.patient', 'radiologyAppointment.radiologyOrderItem.radiologyProcedure');

    expect($report->radiologyAppointment->id)->toBe($appointment->id);
    expect($report->radiologyAppointment->radiologyOrderItem->id)->toBe($ctx['item']->id);
    expect($report->radiologyAppointment->radiologyOrderItem->radiologyOrder->id)->toBe($ctx['order']->id);
    expect($report->radiologyAppointment->radiologyOrderItem->radiologyOrder->clinicalEncounter->id)->toBe($ctx['encounter']->id);
    expect($report->radiologyAppointment->radiologyOrderItem->radiologyOrder->clinicalEncounter->patient->id)->toBe($ctx['patient']->id);
});
