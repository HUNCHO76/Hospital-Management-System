<?php

use App\Models\RadiologyReport;
use App\Services\RadiologyReportService;

/**
 * The Step 44 visibility boundary (Part 15/18/31): only RELEASED reports are
 * ever presented as official clinical results on the doctor's clinical
 * encounter page or the patient's clinical history timeline. A VERIFIED
 * (but not yet released) report must not appear in either place — the
 * radiology order/item itself remains visible (unaffected, Step 38
 * territory), but no result content is shown until release.
 */
test('a verified but not yet released report does not appear on the encounter page as an official result', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('radiologyResults', 0));
});

test('a released report appears on the encounter page as an official result with full clinical content', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    app(RadiologyReportService::class)->release($report, [], requestAs($releaser['user']));

    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('radiologyResults', 1)
            ->where('radiologyResults.0.id', $report->id)
            ->where('radiologyResults.0.status', RadiologyReport::STATUS_RELEASED)
            ->where('radiologyResults.0.findings', $report->fresh()->findings)
            ->where('radiologyResults.0.impression', $report->fresh()->impression)
            ->where('radiologyResults.0.reporting_practitioner.name', fn ($name) => $name !== null)
            ->has('radiologyResults.0.released_at'));
});

test('a verified but not yet released report does not appear in patient clinical history as an official result', function () {
    $ctx = radiologyOrderReadyForScheduling();
    verifiedRadiologyReportFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('encounters.data.0.radiology_results', 0));
});

test('a released report appears in patient clinical history as an official result', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    app(RadiologyReportService::class)->release($report, [], requestAs($releaser['user']));

    $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('encounters.data.0.radiology_results', 1)
            ->where('encounters.data.0.radiology_results.0.id', $report->id)
            ->where('encounters.data.0.radiology_results.0.status', RadiologyReport::STATUS_RELEASED));
});

test('a radiology order remains visible on the encounter page regardless of report release status', function () {
    $ctx = radiologyOrderReadyForScheduling();
    verifiedRadiologyReportFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('radiologyOrders', 1)
            ->has('radiologyResults', 0));
});
