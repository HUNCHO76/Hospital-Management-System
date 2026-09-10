<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratorySample;
use App\Models\ReceptionVisit;

/**
 * The full clinical-to-processing pathway (Phase 4, Step 32, spec §61):
 * Patient -> Reception -> Clinical Encounter -> Consultation -> Diagnosis ->
 * Laboratory Order -> Select Tests -> Sample Collection -> Label -> Receive
 * -> Laboratory Processing Worklist -> Accept Sample -> Assign Technician/
 * Bench -> Start Processing -> Complete Processing -> RESULT PENDING. Stops
 * there — no result entry, verification, or reporting is exercised.
 */
test('a received specimen flows through acceptance, assignment, and processing to result pending', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $technician = userWithRole('laboratory-technician');
    $bench = LaboratoryBench::factory()->create(['name' => 'Hematology Bench 1']);

    $cbc = activeLaboratoryTest(['name' => 'Complete Blood Count', 'code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $malaria = activeLaboratoryTest(['name' => 'Malaria', 'code' => 'MAL', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);

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

    $diagnosisCode = activeDiagnosisCode(['code' => 'B50.9', 'description' => 'Malaria']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();

    // Laboratory order with two tests sharing one specimen.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.laboratory-orders.store', $encounter), [
        'test_ids' => [$cbc->id, $malaria->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Fever, suspected malaria',
    ])->assertRedirect();

    $order = LaboratoryOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    $itemIds = $order->items->pluck('id')->all();

    // Collect, label, and receive the specimen.
    $this->actingAs($nurse)->post(route('laboratory.samples.store', $order), [
        'item_ids' => $itemIds,
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ])->assertRedirect();

    $sample = LaboratorySample::query()->where('laboratory_order_id', $order->id)->firstOrFail();

    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $sample), ['collection_site' => 'Left arm'])->assertRedirect();
    $this->actingAs($nurse)->post(route('laboratory.samples.label', $sample))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.samples.receive', $sample))->assertRedirect();

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_RECEIVED);

    // Receiving auto-initializes one LaboratoryProcessing record per test.
    $this->assertDatabaseCount('laboratory_processing', 2);

    // Processing worklist shows the specimen, with its own tests loaded —
    // the worklist table renders `sample.items` directly (independently of
    // the per-test processing records), so a missing eager load here would
    // crash the page with a runtime "Cannot read properties of undefined
    // (reading 'map')" the moment a human opened it in a browser, even
    // though nothing here asserting on JSON alone would have caught it.
    $worklist = $this->actingAs($technician)->get(route('laboratory.processing.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page
        ->component('laboratory/processing/index')
        ->where('samples.data.0.id', $sample->id)
        ->has('samples.data.0.items', 2));

    // Accept the whole specimen into processing.
    $this->actingAs($technician)->post(route('laboratory.processing.accept', $sample))->assertRedirect();
    $sample->load('processingRecords');
    expect($sample->processingRecords->every(fn ($r) => $r->status === LaboratoryProcessing::STATUS_ACCEPTED))->toBeTrue();

    [$recordA, $recordB] = $sample->processingRecords->values();

    // Detail page is reachable and shows both per-test records.
    $detail = $this->actingAs($technician)->get(route('laboratory.processing.show', $sample));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('laboratory/processing/show')
        ->has('sample.processing_records', 2));

    // Assign each test to the technician and bench.
    $this->actingAs($technician)->post(route('laboratory.processing.assign', $recordA), [
        'assigned_to' => $technician->id,
        'bench_id' => $bench->id,
    ])->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.processing.assign', $recordB), [
        'assigned_to' => $technician->id,
        'bench_id' => $bench->id,
    ])->assertRedirect();

    // Start both tests -> order/item statuses roll up to in_progress.
    $this->actingAs($technician)->post(route('laboratory.processing.start', $recordA->fresh()), ['processing_notes' => 'Loaded on analyzer'])->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.processing.start', $recordB->fresh()))->assertRedirect();
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_IN_PROGRESS);

    // Completing only one test does not advance the order past the other's stage.
    $this->actingAs($technician)->post(route('laboratory.processing.complete', $recordA->fresh()))->assertRedirect();
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_IN_PROGRESS);

    // Completing the second test -> RESULT PENDING for both the item and the order.
    $this->actingAs($technician)->post(route('laboratory.processing.complete', $recordB->fresh()))->assertRedirect();
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_RESULT_PENDING);
    expect($recordA->fresh()->status)->toBe(LaboratoryProcessing::STATUS_RESULT_PENDING);
    expect($recordB->fresh()->status)->toBe(LaboratoryProcessing::STATUS_RESULT_PENDING);
    expect($recordA->fresh()->completed_at)->not->toBeNull();
    expect($recordB->fresh()->completed_at)->not->toBeNull();

    // Full audit trail across the processing lifecycle.
    foreach ([
        'laboratory_order_created', 'laboratory_sample_created', 'laboratory_sample_collected',
        'laboratory_sample_labelled', 'laboratory_sample_received',
        'laboratory_sample_accepted', 'laboratory_sample_assigned',
        'laboratory_sample_processing_started', 'laboratory_sample_processing_completed',
        'laboratory_processing_status_changed', 'laboratory_order_status_changed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

test('a specimen rejected mid-processing cascades to every one of its open tests', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.processing.accept', $ctx['sample']))->assertRedirect();

    $this->actingAs($technician)->post(route('laboratory.processing.reject', $ctx['sample']), [
        'rejection_reason' => 'hemolysed',
    ])->assertRedirect();

    expect($ctx['sample']->fresh()->status)->toBe('rejected');
    $ctx['sample']->fresh()->processingRecords->each(fn ($r) => expect($r->status)->toBe(LaboratoryProcessing::STATUS_REJECTED));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_rejected']);
});
