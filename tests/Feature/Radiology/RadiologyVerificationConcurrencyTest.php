<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (Phase 5, Step 43, Part 21): two verifiers
 * racing to verify (or verify-and-return) the same submitted report must
 * never both succeed. Mirrors RadiologyReportConcurrencyTest's own
 * sequential-back-to-back-attempts convention — verify()/returnForCorrection()
 * lock the report row and re-check its status inside one transaction, so
 * each sequential call below exercises the same guard that would defend
 * against two truly simultaneous requests.
 */
test('two verifiers racing to verify the same report only let one succeed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);

    $verifierA = radiologySupervisorWithPractitioner($ctx['hospital']);
    $verifierB = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $winner = $service->verify($report, [], requestAs($verifierA['user']));

    expect(fn () => $service->verify($report->fresh(), [], requestAs($verifierB['user'])))
        ->toThrow(ValidationException::class);

    expect($winner->verifying_practitioner_id)->toBe($verifierA['practitioner']->id);
    expect(RadiologyReport::query()->where('id', $report->id)->where('status', RadiologyReport::STATUS_VERIFIED)->count())->toBe(1);
});

test('one verifier verifying while another attempts to return the same report only lets the first action win', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);

    $verifierA = radiologySupervisorWithPractitioner($ctx['hospital']);
    $verifierB = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->verify($report, [], requestAs($verifierA['user']));

    expect(fn () => $service->returnForCorrection($report->fresh(), ['return_reason' => 'other', 'return_comments' => 'x'], requestAs($verifierB['user'])))
        ->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('three sequential verify attempts on the same report yield exactly one winner', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $service = app(RadiologyReportService::class);

    $verifiers = [
        radiologySupervisorWithPractitioner($ctx['hospital']),
        radiologySupervisorWithPractitioner($ctx['hospital']),
        radiologySupervisorWithPractitioner($ctx['hospital']),
    ];

    $successes = 0;
    $rejections = 0;

    foreach ($verifiers as $verifier) {
        try {
            $service->verify($report->fresh(), [], requestAs($verifier['user']));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
});
