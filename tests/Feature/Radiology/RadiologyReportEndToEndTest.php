<?php

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologySchedulingService;

/**
 * The full Step 42 pathway (Part 35): doctor orders a Chest X-Ray ->
 * scheduled -> patient arrives -> verified -> preparation -> READY ->
 * radiographer starts and completes the examination -> reporting worklist
 * -> radiologist opens the examination -> reviews context -> enters
 * technique/findings/impression -> saves draft -> reopens -> updates ->
 * submits -> SUBMITTED (the Step 43 handoff point). Also covers the
 * ABORTED-examination-cannot-be-reported branch.
 */
test('the full pathway from a completed examination to a submitted report', function () {
    $ctx = radiologyOrderReadyForScheduling(['code' => 'XR-CHEST-RPT-E2E', 'name' => 'Chest X-Ray (Report E2E)', 'modality' => 'x_ray', 'preparation_type' => 'none']);
    $ctx['order']->update(['clinical_indication' => 'Persistent chest pain and cough.']);
    $scheduler = userWithRole('radiology-technician');

    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
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

    // The reporting worklist surfaces it as not-started.
    $this->actingAs($radiologist['user'])->get(route('radiology-results.index'))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.id', $appointment->id)
            ->where('appointments.data.0.report', null));

    // The radiologist opens the examination preview and starts the report.
    $this->actingAs($radiologist['user'])->get(route('radiology-results.create', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('radiology/results/create')
            ->where('appointment.patient.id', $ctx['patient']->id)
            ->where('appointment.order.order_number', $ctx['order']->order_number));

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();
    expect($report->status)->toBe(RadiologyReport::STATUS_DRAFT);
    expect($report->reporting_practitioner_id)->toBe($radiologist['practitioner']->id);

    // The show page displays the full clinical context, read live from the
    // order (never a separate editable copy).
    $this->actingAs($radiologist['user'])->get(route('radiology-results.show', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('radiology/results/show')
            ->where('report.order.clinical_indication', 'Persistent chest pain and cough.')
            ->where('canUpdate', true)
            ->where('canSubmit', true));

    // Enters technique/findings and saves the draft.
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'technique' => 'PA and lateral views.',
        'findings' => 'Lung fields are clear bilaterally. No focal consolidation, effusion, or pneumothorax.',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_DRAFT);
    expect($report->fresh()->findings)->toContain('Lung fields are clear');

    // Reopens the draft later and updates it with the impression.
    $this->actingAs($radiologist['user'])->get(route('radiology-results.show', $report))->assertOk();

    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'technique' => 'PA and lateral views.',
        'findings' => 'Lung fields are clear bilaterally. No focal consolidation, effusion, or pneumothorax.',
        'impression' => 'No acute cardiopulmonary process.',
        'recommendations' => 'Clinical correlation recommended.',
    ])->assertRedirect();

    // Submits the report.
    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))->assertRedirect();

    $submitted = $report->fresh();
    expect($submitted->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
    expect($submitted->submitted_by)->toBe($radiologist['user']->id);
    expect($submitted->submitted_at)->not->toBeNull();

    // Available to Step 43: queryable by status, fully traceable.
    $submittedReports = RadiologyReport::query()
        ->where('status', RadiologyReport::STATUS_SUBMITTED)
        ->with('radiologyAppointment.radiologyOrderItem.radiologyOrder.clinicalEncounter.patient')
        ->get();

    expect($submittedReports)->toHaveCount(1);
    expect($submittedReports->first()->radiologyAppointment->radiologyOrderItem->radiologyOrder->clinicalEncounter->patient->id)->toBe($ctx['patient']->id);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_created', 'auditable_id' => $report->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_updated', 'auditable_id' => $report->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_report_submitted', 'auditable_id' => $report->id]);

    // The report's history page surfaces this same trail: created, the two
    // separate draft saves above, and the final submission.
    $this->actingAs($radiologist['user'])->get(route('radiology-results.history', $report))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs', 4));
});

test('an aborted examination cannot be reported on', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $examService = app(RadiologyExaminationService::class);

    $examService->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $examService->abort($appointment, 'equipment_problem', requestAs($technician));

    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->get(route('radiology-results.index'))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseCount('radiology_reports', 0);
});
