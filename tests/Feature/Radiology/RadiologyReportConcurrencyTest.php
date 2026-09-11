<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (Part 30): two reporting practitioners racing
 * to start a report on the same completed examination must never both
 * succeed, and sequential draft edits must never silently corrupt each
 * other. Mirrors RadiologyExaminationConcurrencyTest's own back-to-back-
 * attempts convention — createDraft()/submit() lock the relevant row and
 * re-check inside one transaction, so each sequential call below exercises
 * the same guard that would defend against two truly simultaneous requests.
 */
test('two radiologists racing to create a report for the same examination only let one succeed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);

    $radiologistA = radiologistWithPractitioner($ctx['hospital']);
    $radiologistB = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $winner = $service->createDraft($appointment, [], requestAs($radiologistA['user']));

    expect(fn () => $service->createDraft($appointment->fresh(), [], requestAs($radiologistB['user'])))
        ->toThrow(ValidationException::class);

    expect(RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(1);
    expect($winner->reporting_practitioner_id)->toBe($radiologistA['practitioner']->id);
});

test('three sequential create attempts on the same examination yield exactly one winner', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $service = app(RadiologyReportService::class);

    $radiologists = [
        radiologistWithPractitioner($ctx['hospital']),
        radiologistWithPractitioner($ctx['hospital']),
        radiologistWithPractitioner($ctx['hospital']),
    ];

    $successes = 0;
    $rejections = 0;

    foreach ($radiologists as $radiologist) {
        try {
            $service->createDraft($appointment->fresh(), [], requestAs($radiologist['user']));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
});

test('sequential draft edits are serialized without corrupting each other', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, ['findings' => 'First save.'], requestAs($radiologist['user']));

    $service->updateDraft($report->fresh(), ['findings' => 'Second save.'], requestAs($radiologist['user']));
    $final = $service->updateDraft($report->fresh(), ['findings' => 'Third save.', 'impression' => 'Final impression.'], requestAs($radiologist['user']));

    expect($final->findings)->toBe('Third save.');
    expect($final->impression)->toBe('Final impression.');
    expect(RadiologyReport::query()->where('id', $report->id)->count())->toBe(1);
});

test('two sequential submit attempts on the same draft only let one succeed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $report = $service->createDraft($appointment, [
        'findings' => 'Findings.',
        'impression' => 'Impression.',
    ], requestAs($radiologist['user']));

    $service->submit($report->fresh(), requestAs($radiologist['user']));

    expect(fn () => $service->submit($report->fresh(), requestAs($radiologist['user'])))
        ->toThrow(ValidationException::class);

    expect(RadiologyReport::query()->where('id', $report->id)->where('status', RadiologyReport::STATUS_SUBMITTED)->count())->toBe(1);
});
