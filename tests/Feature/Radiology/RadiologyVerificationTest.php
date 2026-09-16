<?php

use App\Models\AuditLog;
use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;
use Illuminate\Validation\ValidationException;

/**
 * Core RadiologyReportService verification behavior (Phase 5, Step 43):
 * verify, return-for-correction, the self-verification rule, and the
 * corrected-report resubmission cycle.
 */
test('a submitted report can be verified by an independent practitioner, recording verifier metadata', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $verified = app(RadiologyReportService::class)->verify($report, ['verification_notes' => 'Reviewed and concur.'], requestAs($verifier['user']));

    expect($verified->status)->toBe(RadiologyReport::STATUS_VERIFIED);
    expect($verified->verifying_practitioner_id)->toBe($verifier['practitioner']->id);
    expect($verified->verified_by)->toBe($verifier['user']->id);
    expect($verified->verified_at)->not->toBeNull();
    expect($verified->verification_notes)->toBe('Reviewed and concur.');
});

test('a reporting practitioner cannot verify their own report by default', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $reporter = radiologySupervisorWithPractitioner($ctx['hospital']);
    $report = submittedRadiologyReportFor($ctx, $reporter);

    expect(fn () => app(RadiologyReportService::class)->verify($report, [], requestAs($reporter['user'])))
        ->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('self-verification is allowed when hospital policy explicitly enables it', function () {
    config(['radiology.allow_self_verification' => true]);

    $ctx = radiologyOrderReadyForScheduling();
    $reporter = radiologySupervisorWithPractitioner($ctx['hospital']);
    $report = submittedRadiologyReportFor($ctx, $reporter);

    $verified = app(RadiologyReportService::class)->verify($report, [], requestAs($reporter['user']));

    expect($verified->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('a verified report cannot be verified again', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->verify($report, [], requestAs($verifier['user']));

    expect(fn () => $service->verify($report->fresh(), [], requestAs($verifier['user'])))
        ->toThrow(ValidationException::class);
});

test('a submitted report can be returned for correction, requiring a reason', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $returned = app(RadiologyReportService::class)->returnForCorrection($report, [
        'return_reason' => 'unclear_impression',
        'return_comments' => 'Please clarify the impression.',
    ], requestAs($verifier['user']));

    expect($returned->status)->toBe(RadiologyReport::STATUS_RETURNED);
    expect($returned->returned_by)->toBe($verifier['user']->id);
    expect($returned->returned_at)->not->toBeNull();
    expect($returned->return_reason)->toBe('unclear_impression');
    expect($returned->return_comments)->toBe('Please clarify the impression.');
});

test('a returned report can be corrected and resubmitted, and verified on the second review', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $report = submittedRadiologyReportFor($ctx, $reporter);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->returnForCorrection($report, ['return_reason' => 'incomplete_findings'], requestAs($verifier['user']));
    $report->refresh();
    expect($report->status)->toBe(RadiologyReport::STATUS_RETURNED);

    $corrected = $service->updateDraft($report, [
        'findings' => 'Corrected, complete findings.',
        'impression' => 'Unremarkable study.',
    ], requestAs($reporter['user']));
    expect($corrected->status)->toBe(RadiologyReport::STATUS_DRAFT);
    // Return history remains visible even after correction (Part 12).
    expect($corrected->return_reason)->toBe('incomplete_findings');

    $resubmitted = $service->submit($corrected, requestAs($reporter['user']));
    expect($resubmitted->status)->toBe(RadiologyReport::STATUS_SUBMITTED);

    $verified = $service->verify($resubmitted, [], requestAs($verifier['user']));
    expect($verified->status)->toBe(RadiologyReport::STATUS_VERIFIED);

    // No duplicate report was ever created for this examination.
    expect(RadiologyReport::query()->where('radiology_appointment_id', $report->radiology_appointment_id)->count())->toBe(1);
});

test('a verified report cannot be edited through the normal draft route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $verified = $service->verify($report, [], requestAs($verifier['user']));

    expect(fn () => $service->updateDraft($verified, ['findings' => 'tampered'], requestAs($verifier['user'])))
        ->toThrow(ValidationException::class);
});

test('reviewing a report opened for verification only logs an audit event when it is still SUBMITTED', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $service = app(RadiologyReportService::class);

    $service->recordReviewStarted($report, requestAs($verifier['user']));
    expect(AuditLog::query()->where('action', 'radiology_report_review_started')->count())->toBe(1);

    $verified = $service->verify($report, [], requestAs($verifier['user']));

    // Re-opening an already-decided report does not add another review-started entry.
    $service->recordReviewStarted($verified, requestAs($verifier['user']));
    expect(AuditLog::query()->where('action', 'radiology_report_review_started')->count())->toBe(1);
});
