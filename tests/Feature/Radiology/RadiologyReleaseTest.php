<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Core RadiologyReportService release behavior (Phase 5, Step 44): the
 * minimum valid transition VERIFIED -> RELEASED, release metadata, and
 * re-release protection.
 */
test('a verified report can be released, recording release metadata', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $released = app(RadiologyReportService::class)->release($report, ['release_notes' => 'Reviewed and released.'], requestAs($releaser['user']));

    expect($released->status)->toBe(RadiologyReport::STATUS_RELEASED);
    expect($released->released_by)->toBe($releaser['user']->id);
    expect($released->released_at)->not->toBeNull();
    expect($released->release_notes)->toBe('Reviewed and released.');
});

test('release does not require release_notes', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $released = app(RadiologyReportService::class)->release($report, [], requestAs($releaser['user']));

    expect($released->status)->toBe(RadiologyReport::STATUS_RELEASED);
    expect($released->release_notes)->toBeNull();
});

test('a released report cannot be released again', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->release($report, [], requestAs($releaser['user']));

    expect(fn () => $service->release($report->fresh(), [], requestAs($releaser['user'])))
        ->toThrow(ValidationException::class);

    expect(RadiologyReport::query()->where('id', $report->id)->where('status', RadiologyReport::STATUS_RELEASED)->count())->toBe(1);
});

test('a released report cannot be edited through the normal draft route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $released = $service->release($report, [], requestAs($releaser['user']));

    expect(fn () => $service->updateDraft($released, ['findings' => 'tampered'], requestAs($releaser['user'])))
        ->toThrow(ValidationException::class);

    expect($released->fresh()->findings)->not->toBe('tampered');
});

test('release preserves the report content unchanged', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $originalFindings = $report->findings;
    $originalImpression = $report->impression;

    $released = app(RadiologyReportService::class)->release($report, [], requestAs($releaser['user']));

    expect($released->findings)->toBe($originalFindings);
    expect($released->impression)->toBe($originalImpression);
});

test('RadiologyReport::TRANSITIONS implements the Step 44 minimum transition and terminal state', function () {
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_VERIFIED])->toBe([RadiologyReport::STATUS_RELEASED]);
    expect(RadiologyReport::TRANSITIONS[RadiologyReport::STATUS_RELEASED])->toBe([]);
});
