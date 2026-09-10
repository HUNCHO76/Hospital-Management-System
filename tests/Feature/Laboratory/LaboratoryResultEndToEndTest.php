<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\ReceptionVisit;

/**
 * The full clinical-to-result pathway (Phase 4, Step 33, spec §45): Patient
 * -> Reception -> Clinical Encounter -> Consultation -> Diagnosis ->
 * Laboratory Order -> Select Tests -> Sample Collection -> Label -> Receive
 * -> Laboratory Processing -> Accept -> Start -> Complete -> RESULT PENDING
 * -> Open Result Worklist -> Enter Result -> Validate -> Submit ->
 * PENDING VERIFICATION. Stops there — no verification/release is exercised.
 */
test('a completed test flows through result entry, validation, and submission to pending verification', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $technician = userWithRole('laboratory-technician');
    $bench = LaboratoryBench::factory()->create();

    $hb = activeLaboratoryTest([
        'name' => 'Hemoglobin', 'code' => 'HB', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube',
        'result_type' => 'numeric', 'unit' => 'g/dL', 'reference_range' => '13.5-17.5',
    ]);

    // Reception -> Clinical Encounter -> Triage -> Consultation -> Diagnosis.
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

    expect($record->fresh()->status)->toBe(LaboratoryProcessing::STATUS_RESULT_PENDING);

    // Completing processing auto-created the result-pending record.
    $this->assertDatabaseCount('laboratory_results', 1);
    $result = LaboratoryResult::where('laboratory_order_item_id', $item->id)->sole();
    expect($result->status)->toBe(LaboratoryResult::STATUS_RESULT_PENDING);
    expect($result->result_type)->toBe('numeric');
    expect($result->unit)->toBe('g/dL');

    // The result worklist shows it.
    $worklist = $this->actingAs($technician)->get(route('laboratory.results.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page->component('laboratory/results/index')->where('results.data.0.id', $result->id));

    // The detail page is reachable and shows full traceability.
    $detail = $this->actingAs($technician)->get(route('laboratory.results.show', $result));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('laboratory/results/show')
        ->where('result.patient.id', $ctx['patient']->id)
        ->where('result.laboratory_order.id', $order->id)
        ->where('result.sample.id', $sample->id));

    // Attempting an invalid value is rejected — Laravel remains authoritative.
    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => 'not-a-number'])
        ->assertSessionHasErrors('value_numeric');

    // Enter a valid result and submit it for verification.
    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => '11.2', 'abnormal_flag' => 'low'])
        ->assertRedirect();

    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_DRAFT);

    $this->actingAs($technician)->post(route('laboratory.results.submit', $result->fresh()))->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->value_numeric)->toBe('11.2000');
    expect($result->abnormal_flag)->toBe('low');
    expect($result->entered_by)->toBe($technician->id);
    expect($result->submitted_by)->toBe($technician->id);
    expect($result->submitted_at)->not->toBeNull();

    // The order/patient/sample/test identity is fully traceable from the result.
    expect($result->item->laboratoryOrder->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($result->laboratory_sample_id)->toBe($sample->id);

    // Full audit trail across the handoff.
    foreach ([
        'laboratory_order_created', 'laboratory_sample_created', 'laboratory_sample_received',
        'laboratory_sample_accepted', 'laboratory_sample_processing_started', 'laboratory_sample_processing_completed',
        'laboratory_result_created', 'laboratory_result_submitted', 'laboratory_result_status_changed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});
