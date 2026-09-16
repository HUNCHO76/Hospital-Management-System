<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;

test('returning a report without a reason is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [])
        ->assertSessionHasErrors('return_reason');

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('returning a report with an unrecognized reason is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'not_a_real_reason',
    ])->assertSessionHasErrors('return_reason');
});

test('returning a report with reason "other" requires comments', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'other',
    ])->assertSessionHasErrors('return_comments');

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'other',
        'return_comments' => 'Please see attached note.',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RETURNED);
});

test('returning a report with a non-"other" reason does not require comments', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'typographical_error',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RETURNED);
});

test('verification_notes exceeding the length limit is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report), [
        'verification_notes' => str_repeat('a', 1001),
    ])->assertSessionHasErrors('verification_notes');
});

test('a draft report cannot be verified or returned through the verification routes', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))
        ->assertSessionHasErrors('status');

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'other',
        'return_comments' => 'x',
    ])->assertSessionHasErrors('status');
});

test('a verified report cannot be verified again through the verification route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))->assertRedirect();

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))
        ->assertSessionHasErrors('status');
});

test('a verified report cannot be returned through the verification route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))->assertRedirect();

    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'other',
        'return_comments' => 'x',
    ])->assertSessionHasErrors('status');
});

test('a client-supplied verifier id or facility id is ignored', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $otherPractitioner = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report), [
        'verifying_practitioner_id' => $otherPractitioner['practitioner']->id,
        'facility_id' => 999999,
    ])->assertRedirect();

    expect($report->fresh()->verifying_practitioner_id)->toBe($verifier['practitioner']->id);
});
