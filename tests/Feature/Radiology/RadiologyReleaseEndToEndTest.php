<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Services\RadiologySchedulingService;

/**
 * The full Step 44 pathway (Part 37): doctor orders a Chest X-Ray ->
 * scheduled -> patient arrives -> prepared -> examined -> COMPLETED ->
 * radiologist records findings -> SUBMITTED -> verifier reviews -> VERIFIED
 * -> release worklist -> authorized user releases -> RELEASED -> doctor
 * opens the encounter -> Radiology Results section -> released result
 * visible -> patient history updated. Also covers the return path: normal
 * edit is blocked once RELEASED.
 */
test('the full pathway from order through verification to release and encounter/history visibility', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-REL-E2E', 'name' => 'Chest X-Ray (Release E2E)', 'modality' => 'x_ray', 'preparation_type' => 'none']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room E2E-44',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();
    $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment))->assertRedirect();
    $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))->assertRedirect();

    $technician = userWithRole('radiology-technician');
    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();
    $this->actingAs($technician)->post(route('radiology-examinations.complete', $appointment))->assertRedirect();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);

    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'technique' => 'PA and lateral views.',
        'findings' => 'Lung fields are clear bilaterally.',
        'impression' => 'No acute cardiopulmonary process.',
        'recommendations' => 'Clinical correlation recommended.',
    ])->assertRedirect();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);

    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $this->actingAs($verifier['user'])->get(route('radiology-verification.review', $report))->assertOk();
    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);

    // Not yet released: the doctor's encounter page shows no official result.
    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertInertia(fn ($page) => $page->has('radiologyResults', 0));

    // The release worklist surfaces it as ready for release.
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    $this->actingAs($releaser['user'])->get(route('radiology-release.index'))
        ->assertInertia(fn ($page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $report->id)
            ->where('reports.data.0.status', 'verified'));

    $this->actingAs($releaser['user'])->get(route('radiology-release.show', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('radiology/release/show')
            ->where('report.findings', 'Lung fields are clear bilaterally.')
            ->where('canRelease', true));

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report), [
        'release_notes' => 'Reviewed prior to release.',
    ])->assertRedirect();

    $released = $report->fresh();
    expect($released->status)->toBe(RadiologyReport::STATUS_RELEASED);
    expect($released->released_by)->toBe($releaser['user']->id);
    expect($released->released_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_result_released', 'auditable_id' => $report->id]);

    // Doctor opens the encounter — the released result is now visible
    // automatically, without needing to import or attach it manually.
    $this->actingAs($ctx['doctor']['user'])->get(route('clinical-encounters.show', $ctx['encounter']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('radiologyResults', 1)
            ->where('radiologyResults.0.id', $report->id)
            ->where('radiologyResults.0.findings', 'Lung fields are clear bilaterally.')
            ->where('radiologyResults.0.impression', 'No acute cardiopulmonary process.'));

    // Patient history is updated too.
    $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('encounters.data.0.radiology_results', 1));

    // Once RELEASED, the normal report editing route is blocked.
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), ['findings' => 'tampered'])
        ->assertSessionHasErrors('status');
    expect($report->fresh()->findings)->not->toBe('tampered');

    // And it cannot be released a second time.
    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report))
        ->assertSessionHasErrors('status');

    // No duplicate report was ever created across the whole pathway.
    expect(RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(1);
});
