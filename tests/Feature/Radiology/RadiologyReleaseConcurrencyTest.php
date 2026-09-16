<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (Phase 5, Step 44, Part 11/36): two users
 * racing to release the same verified report must never both succeed.
 * Mirrors RadiologyVerificationConcurrencyTest's own sequential-back-to-back
 * convention — release() locks the report row and re-checks its status
 * inside one transaction, so each sequential call below exercises the same
 * guard that would defend against two truly simultaneous requests.
 */
test('two users racing to release the same result only let one succeed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);

    $userA = radiologySupervisorWithPractitioner($ctx['hospital']);
    $userB = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $winner = $service->release($report, [], requestAs($userA['user']));

    expect(fn () => $service->release($report->fresh(), [], requestAs($userB['user'])))
        ->toThrow(ValidationException::class);

    expect($winner->released_by)->toBe($userA['user']->id);
    expect(RadiologyReport::query()->where('id', $report->id)->where('status', RadiologyReport::STATUS_RELEASED)->count())->toBe(1);
});

test('three sequential release attempts on the same report yield exactly one winner', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $service = app(RadiologyReportService::class);

    $releasers = [
        radiologySupervisorWithPractitioner($ctx['hospital']),
        radiologySupervisorWithPractitioner($ctx['hospital']),
        radiologySupervisorWithPractitioner($ctx['hospital']),
    ];

    $successes = 0;
    $rejections = 0;

    foreach ($releasers as $releaser) {
        try {
            $service->release($report->fresh(), [], requestAs($releaser['user']));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(2);
});
