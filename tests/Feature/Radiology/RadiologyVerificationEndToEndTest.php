<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Services\RadiologySchedulingService;

/**
 * The full Step 43 pathway (Part 34): doctor orders a Chest X-Ray ->
 * scheduled -> patient arrives -> prepared -> examined -> COMPLETED ->
 * radiologist creates a report, enters findings/impression -> SUBMITTED ->
 * verifier opens the verification worklist -> reviews examination/findings/
 * impression -> returns the report -> radiologist corrects it -> submits
 * again -> verifier reviews again -> approves -> VERIFIED, ready for Step
 * 44. Also covers the successful path with no return.
 */
test('the full pathway from a submitted report through a return-correct-resubmit cycle to VERIFIED', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-VER-E2E', 'name' => 'Chest X-Ray (Verification E2E)', 'modality' => 'x_ray', 'preparation_type' => 'none']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room E2E-43',
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
        'impression' => 'No acute process.',
    ])->assertRedirect();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);

    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    // The verifier's worklist surfaces it as pending verification.
    $this->actingAs($verifier['user'])->get(route('radiology-verification.index'))
        ->assertInertia(fn ($page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $report->id)
            ->where('reports.data.0.status', 'submitted'));

    // The verifier opens the report and reviews the full context.
    $this->actingAs($verifier['user'])->get(route('radiology-verification.review', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('radiology/verification/show')
            ->where('report.patient.id', $ctx['patient']->id)
            ->where('report.order.order_number', $ctx['order']->order_number)
            ->where('report.findings', 'Lung fields are clear bilaterally.')
            ->where('report.impression', 'No acute process.')
            ->where('canVerify', true)
            ->where('canReturn', true));

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_review_started', 'auditable_id' => $report->id]);

    // The verifier is not satisfied and returns it for correction.
    $this->actingAs($verifier['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'unclear_impression',
        'return_comments' => 'Please clarify whether this excludes pneumonia.',
    ])->assertRedirect();

    $returned = $report->fresh();
    expect($returned->status)->toBe(RadiologyReport::STATUS_RETURNED);
    expect($returned->returned_by)->toBe($verifier['user']->id);

    // The radiologist corrects the report and submits it again.
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'technique' => 'PA and lateral views.',
        'findings' => 'Lung fields are clear bilaterally. No focal consolidation.',
        'impression' => 'No acute cardiopulmonary process; pneumonia is not favored.',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_DRAFT);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))->assertRedirect();
    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_resubmitted', 'auditable_id' => $report->id]);

    // The verifier reviews again and this time approves it.
    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report), [
        'verification_notes' => 'Corrected impression reviewed and approved.',
    ])->assertRedirect();

    $verified = $report->fresh();
    expect($verified->status)->toBe(RadiologyReport::STATUS_VERIFIED);
    expect($verified->verifying_practitioner_id)->toBe($verifier['practitioner']->id);
    expect($verified->verified_by)->toBe($verifier['user']->id);
    expect($verified->verified_at)->not->toBeNull();

    // No duplicate report was ever created across the whole cycle.
    expect(RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(1);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_returned', 'auditable_id' => $report->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_verified', 'auditable_id' => $report->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_verification_note_added', 'auditable_id' => $report->id]);

    // Once VERIFIED, the report is immutable through the normal edit route.
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), ['findings' => 'tampered'])
        ->assertSessionHasErrors('status');
    expect($report->fresh()->findings)->not->toBe('tampered');
});

test('the successful path with no return: submit straight to verified', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->get(route('radiology-verification.review', $report))->assertOk();

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report))->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});
