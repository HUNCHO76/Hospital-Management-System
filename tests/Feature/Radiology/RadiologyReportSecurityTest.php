<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;

/**
 * XSS and mass-assignment protection (Phase 5, Step 42, Parts 22/31). This
 * app's reporting page renders findings/impression/technique/recommendations
 * as plain text (React's default `{value}` interpolation, which HTML-escapes
 * automatically) — no `dangerouslySetInnerHTML` exists anywhere in the
 * radiology report components (RadiologyReportForm/RadiologyReportShow only
 * ever bind these values into a controlled <Textarea> or plain text nodes).
 * Stored-XSS protection therefore comes from *never rendering the content as
 * HTML* in the first place, not from stripping/mangling it — this test
 * proves the stored value is preserved exactly (so legitimate clinical text
 * containing "<" or ">" is never corrupted) while the structural absence of
 * any raw-HTML rendering path is what actually prevents execution.
 */
test('a malicious script payload in findings is stored verbatim, never executed or corrupted', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

    $report = app(RadiologyReportService::class)->createDraft($appointment, [
        'findings' => $payload,
        'impression' => 'Normal impression text.',
    ], requestAs($radiologist['user']));

    expect($report->fresh()->findings)->toBe($payload);

    // The React radiology report components never use
    // dangerouslySetInnerHTML for report content — confirmed by source
    // inspection (grep across resources/js/pages/radiology/results and
    // resources/js/components/radiology). Findings/impression only ever
    // reach the DOM through plain-text interpolation or a controlled
    // <Textarea value=...>, both of which HTML-escape automatically.
});

test('the client cannot set report status directly through the update endpoint', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));

    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'findings' => 'x',
        'status' => RadiologyReport::STATUS_SUBMITTED,
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_DRAFT);
});

test('the client cannot set the reporting practitioner directly through create or update', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $otherRadiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
        'reporting_practitioner_id' => $otherRadiologist['practitioner']->id,
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    expect($report->reporting_practitioner_id)->toBe($radiologist['practitioner']->id);
    expect($report->reporting_practitioner_id)->not->toBe($otherRadiologist['practitioner']->id);
});

test('no radiology report component uses dangerouslySetInnerHTML', function () {
    $files = array_merge(
        glob(base_path('resources/js/pages/radiology/results/*.tsx')),
        glob(base_path('resources/js/components/radiology/radiology-report*.tsx')),
        glob(base_path('resources/js/components/radiology/radiology-examination-summary.tsx')),
        glob(base_path('resources/js/components/radiology/radiology-submit-report-dialog.tsx')),
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        // Matches the actual JSX prop usage (dangerouslySetInnerHTML=...),
        // not an incidental mention of the term in a doc comment.
        expect((bool) preg_match('/dangerouslySetInnerHTML\s*=/', file_get_contents($file)))
            ->toBeFalse("{$file} must never render report content as raw HTML.");
    }
});
