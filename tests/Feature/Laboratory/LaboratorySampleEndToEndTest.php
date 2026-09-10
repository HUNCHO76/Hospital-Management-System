<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\ReceptionVisit;

/**
 * The full order-to-sample handoff (Phase 4, Step 31, spec §61): Patient ->
 * Reception -> Clinical Encounter -> Triage -> Consultation -> Diagnosis ->
 * Laboratory Order -> Select Tests -> Laboratory Worklist -> Sample
 * Requirement -> Collect Sample -> Generate Sample Identifier -> Label
 * Sample -> Receive Sample -> READY FOR PROCESSING -> Receive/Accept
 * (Phase 4 Accept/Receive fix) -> ACCEPTED. Stops there — result entry and
 * reporting are exercised by their own dedicated end-to-end tests.
 */
test('a laboratory order flows through collection, labelling, and receipt to ready-for-processing', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $technician = userWithRole('laboratory-technician');

    $cbc = activeLaboratoryTest(['name' => 'Complete Blood Count', 'code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $malaria = activeLaboratoryTest(['name' => 'Malaria', 'code' => 'MAL', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $urinalysis = activeLaboratoryTest(['name' => 'Urinalysis', 'code' => 'UA', 'specimen_type' => 'urine']);

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

    // Laboratory order with three tests across two specimen requirements.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.laboratory-orders.store', $encounter), [
        'test_ids' => [$cbc->id, $malaria->id, $urinalysis->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Fever, suspected malaria',
    ])->assertRedirect();

    $order = LaboratoryOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    $bloodItems = $order->items->whereIn('test_code', ['CBC', 'MAL'])->pluck('id')->all();
    $urineItem = $order->items->firstWhere('test_code', 'UA');

    // Collection worklist shows the order.
    $worklist = $this->actingAs($nurse)->get(route('laboratory.collection.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page->component('laboratory/collection/index')->where('orders.data.0.id', $order->id));

    // Register one specimen covering CBC + Malaria (compatible), another for Urinalysis.
    $this->actingAs($nurse)->post(route('laboratory.samples.store', $order), [
        'item_ids' => $bloodItems,
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ])->assertRedirect();

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $order), [
        'item_ids' => [$urineItem->id],
        'specimen_type' => 'urine',
    ])->assertRedirect();

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_SAMPLE_PENDING);

    $bloodSample = LaboratorySample::query()->where('specimen_type', 'whole_blood')->where('laboratory_order_id', $order->id)->firstOrFail();
    $urineSample = LaboratorySample::query()->where('specimen_type', 'urine')->where('laboratory_order_id', $order->id)->firstOrFail();

    // Collect, label, and receive the blood specimen.
    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $bloodSample), ['collection_site' => 'Left arm'])->assertRedirect();
    $this->actingAs($nurse)->post(route('laboratory.samples.label', $bloodSample))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.samples.receive', $bloodSample))->assertRedirect();

    // Order is not "fully collected" while the urine specimen is still pending.
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_SAMPLE_PENDING);

    // Collect, label, and receive the urine specimen.
    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $urineSample))->assertRedirect();
    $this->actingAs($nurse)->post(route('laboratory.samples.label', $urineSample))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.samples.receive', $urineSample))->assertRedirect();

    // Every test received -> order is READY FOR PROCESSING.
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_RECEIVED);
    $order->fresh()->items->each(fn ($item) => expect($item->status)->toBe('received'));

    // Sample detail and label pages are reachable. Once RECEIVED, the
    // specimen's own status never advances further (Accept lives on
    // LaboratoryProcessing) — the detail page must therefore expose the
    // rolled-up `processing_status` too, or a laboratory user landing here
    // has no way to tell whether the specimen still needs accepting.
    $detail = $this->actingAs($technician)->get(route('laboratory.samples.show', $bloodSample));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('laboratory/samples/show')
        ->where('sample.sample_number', $bloodSample->fresh()->sample_number)
        ->where('sample.status', 'received')
        ->where('sample.processing_status', 'received')
        ->has('sample.history', 4)); // created, collected, labelled, received

    // The collection detail page carries the same rolled-up processing
    // status for every one of its specimens.
    $collectionDetail = $this->actingAs($technician)->get(route('laboratory.collection.show', $order));
    $collectionDetail->assertOk();
    $collectionDetail->assertInertia(fn ($page) => $page
        ->component('laboratory/collection/show')
        ->where('samples.0.processing_status', 'received'));

    // Accepting the specimen (Phase 4 Accept/Receive fix) — reachable from
    // the sample's own status, and immediately reflected back on the same
    // detail page so the next action (assign a technician) is visible.
    $this->actingAs($technician)->post(route('laboratory.processing.accept', $bloodSample))->assertRedirect();

    $acceptedDetail = $this->actingAs($technician)->get(route('laboratory.samples.show', $bloodSample));
    $acceptedDetail->assertOk();
    $acceptedDetail->assertInertia(fn ($page) => $page
        ->component('laboratory/samples/show')
        ->where('sample.status', 'received')
        ->where('sample.processing_status', 'accepted'));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_accepted']);

    $labelView = $this->actingAs($nurse)->get(route('laboratory.samples.label-view', $urineSample));
    $labelView->assertOk();
    $labelView->assertInertia(fn ($page) => $page->component('laboratory/samples/label')->where('sample.sample_number', $urineSample->fresh()->sample_number));

    // Reprinting the already-labelled blood sample's label does not create a new sample or change its number.
    $originalNumber = $bloodSample->fresh()->sample_number;
    $this->actingAs($nurse)->post(route('laboratory.samples.label', $bloodSample))->assertRedirect();
    expect($bloodSample->fresh()->sample_number)->toBe($originalNumber);
    expect($bloodSample->fresh()->status)->toBe('received');

    // Full audit trail across the handoff.
    foreach ([
        'clinical_encounter_created', 'triage_created', 'consultation_started', 'diagnosis_created',
        'laboratory_order_created', 'laboratory_sample_created', 'laboratory_sample_collected',
        'laboratory_sample_labelled', 'laboratory_sample_received', 'laboratory_sample_label_reprinted',
        'laboratory_order_status_changed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});
