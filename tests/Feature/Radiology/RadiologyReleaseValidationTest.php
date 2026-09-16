<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyReportService;

/**
 * Release eligibility (Phase 5, Step 44, Part 4-5): only a VERIFIED report
 * may be released. DRAFT, SUBMITTED, and RETURNED reports must all be
 * rejected server-side, whether reached through the HTTP layer or the
 * service directly.
 */
test('a DRAFT report cannot be released', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))
        ->assertSessionHasErrors('status');

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_DRAFT);
});

test('a SUBMITTED report cannot be released', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))
        ->assertSessionHasErrors('status');

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('a RETURNED report cannot be released', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    app(RadiologyReportService::class)->returnForCorrection($report, ['return_reason' => 'other', 'return_comments' => 'x'], requestAs($verifier['user']));

    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))
        ->assertSessionHasErrors('status');

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RETURNED);
});

test('an aborted examination never produces a releasable report', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $examService = app(RadiologyExaminationService::class);
    $examService->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $examService->abort($appointment, 'equipment_problem', requestAs($technician));

    // No report can even be created for an aborted examination (Step 42's
    // own eligibility gate), so no report row exists to attempt releasing.
    $this->assertDatabaseCount('radiology_reports', 0);
});

test('release_notes exceeding the length limit is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report), [
        'release_notes' => str_repeat('a', 1001),
    ])->assertSessionHasErrors('release_notes');

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('a released report cannot be released again through the HTTP route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))->assertRedirect();

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))
        ->assertSessionHasErrors('status');
});

test('a client-supplied released_by, facility_id, or patient_id is ignored', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    $otherUser = userWithRole('radiologist');

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report), [
        'released_by' => $otherUser->id,
        'facility_id' => 999999,
        'patient_id' => 999999,
        'status' => 'released',
    ])->assertRedirect();

    expect($report->fresh()->released_by)->toBe($releaser['user']->id);
});
