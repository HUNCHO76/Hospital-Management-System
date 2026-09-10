<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\ReceptionVisit;

/**
 * The full clinical-to-release pathway (Phase 4, Step 35, spec Part 43):
 * Patient -> Reception -> Clinical Encounter -> Consultation -> Diagnosis ->
 * Laboratory Order -> Sample Collection -> Receipt -> Processing ->
 * RESULT PENDING -> Result Entry -> Submission -> Verification ->
 * Authorization -> READY_FOR_RELEASE -> Release -> Report Generation ->
 * Patient History -> Clinician View -> Print/Download.
 */
test('a released result produces a report, appears in patient history, and can be viewed/printed/downloaded by a clinician', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $technician = userWithRole('laboratory-technician');
    $supervisor = userWithRole('laboratory-supervisor');
    $bench = LaboratoryBench::factory()->create();

    $hb = activeLaboratoryTest([
        'name' => 'Hemoglobin', 'code' => 'HB', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube',
        'result_type' => 'numeric', 'unit' => 'g/dL', 'reference_range' => '13.5-17.5',
    ]);

    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertRedirect();
    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'walk_in',
    ])->assertRedirect();
    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($nurse)->post(route('clinical-encounters.start', $encounter))->assertRedirect();

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 88])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();

    $diagnosisCode = activeDiagnosisCode(['code' => 'D64.9', 'description' => 'Anaemia']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.laboratory-orders.store', $encounter), [
        'test_ids' => [$hb->id],
        'priority' => 'routine',
        'clinical_indication' => 'Fatigue, suspected anaemia',
    ])->assertRedirect();

    $order = LaboratoryOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    $item = $order->items->sole();

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $order), [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ])->assertRedirect();

    $sample = LaboratorySample::query()->where('laboratory_order_id', $order->id)->firstOrFail();

    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $sample), ['collection_site' => 'Left arm'])->assertRedirect();
    $this->actingAs($nurse)->post(route('laboratory.samples.label', $sample))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.samples.receive', $sample))->assertRedirect();

    $this->actingAs($technician)->post(route('laboratory.processing.accept', $sample))->assertRedirect();
    $record = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->sole();

    $this->actingAs($technician)->post(route('laboratory.processing.assign', $record), [
        'assigned_to' => $technician->id,
        'bench_id' => $bench->id,
    ])->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.processing.start', $record->fresh()))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.processing.complete', $record->fresh()))->assertRedirect();

    $result = LaboratoryResult::where('laboratory_order_item_id', $item->id)->sole();
    expect($result->status)->toBe(LaboratoryResult::STATUS_RESULT_PENDING);

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => '11.2', 'abnormal_flag' => 'low'])
        ->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.results.submit', $result->fresh()))->assertRedirect();

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $result->fresh()))->assertRedirect();

    $result = $result->fresh();
    expect($result->isReadyForRelease())->toBeTrue();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.release', $result), ['release_comment' => 'Confirmed, releasing to clinician.'])
        ->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_RELEASED);
    expect($result->released_by)->toBe($supervisor->id);

    $report = LaboratoryReport::where('laboratory_order_id', $order->id)->sole();
    expect($report->version)->toBe(1);
    expect($report->status)->toBe(LaboratoryReport::STATUS_ACTIVE);
    expect($report->results_snapshot)->toHaveCount(1);

    // Released result appears through the authoritative patient history.
    $history = $this->actingAs($doctor['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $history->assertOk();
    $history->assertInertia(fn ($page) => $page
        ->component('patients/clinical-history')
        ->where('encounters.data.0.laboratory_results.0.status', 'released')
        ->where('encounters.data.0.laboratory_results.0.id', $result->id));

    // Clinician can view, print, and download the report.
    $view = $this->actingAs($doctor['user'])->get(route('laboratory.reports.show', $report));
    $view->assertOk();
    $view->assertInertia(fn ($page) => $page
        ->component('laboratory/reports/show')
        ->where('report.report_number', $report->report_number)
        ->where('report.patient.id', $ctx['patient']->id)
        ->has('report.results.0.results', 1));

    $this->actingAs($doctor['user'])->post(route('laboratory.reports.print', $report))->assertRedirect();
    expect($report->fresh()->printed_by)->toBe($doctor['user']->id);

    $this->actingAs($doctor['user'])->post(route('laboratory.reports.download', $report))->assertRedirect();
    expect($report->fresh()->downloaded_by)->toBe($doctor['user']->id);

    // Full audit trail across the entire pathway.
    foreach ([
        'laboratory_order_created', 'laboratory_sample_created', 'laboratory_sample_received',
        'laboratory_result_created', 'laboratory_result_submitted', 'laboratory_result_verified',
        'laboratory_result_authorized', 'laboratory_result_released',
        'laboratory_report_generated', 'laboratory_report_printed', 'laboratory_report_downloaded',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

/**
 * The amendment-after-release branch (spec Part 43): Released Result ->
 * Amendment Request -> Correction -> Re-verification -> Re-authorization ->
 * New Report Version -> Released Amended Report.
 */
test('a released result can be amended, re-verified, re-authorized, and re-released producing a new report version', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric', 'unit' => 'mg/dL']);
    $ctx = releasedForItem($test, ['value_numeric' => '999']);
    $supervisor = $ctx['supervisor'];

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.amend', $ctx['result']), ['value_numeric' => '95', 'amendment_reason' => 'Instrument recalibration correction'])
        ->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($ctx['report']->fresh()->status)->toBe(LaboratoryReport::STATUS_SUPERSEDED);

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $result))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.release', $result->fresh()))->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_RELEASED);
    expect($result->value_numeric)->toBe('95.0000');

    $newReport = LaboratoryReport::where('laboratory_order_id', $result->item->laboratory_order_id)
        ->where('status', LaboratoryReport::STATUS_ACTIVE)
        ->sole();

    expect($newReport->version)->toBe(2);
    expect($newReport->results_snapshot[0]['value_numeric'])->toBe('95.0000');
    expect($ctx['report']->fresh()->results_snapshot[0]['value_numeric'])->toBe('999.0000');

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_result_amended_after_release', 'auditable_id' => $result->id]);
});
