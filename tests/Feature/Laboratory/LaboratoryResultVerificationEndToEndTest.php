<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\ReceptionVisit;

/**
 * The full clinical-to-release-readiness pathway (Phase 4, Step 34, spec
 * §44): Patient -> Reception -> Clinical Encounter -> Consultation ->
 * Diagnosis -> Laboratory Order -> Sample Collection -> Receipt ->
 * Processing -> RESULT PENDING -> Result Entry -> PENDING VERIFICATION ->
 * Verification Worklist -> Review -> Verify -> Authorize -> READY FOR
 * RELEASE. Stops there — no release/reporting is exercised.
 */
test('a submitted result flows through review, verification, and authorization to ready for release', function () {
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

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);

    // Verification worklist shows it.
    $worklist = $this->actingAs($supervisor)->get(route('laboratory.results.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page->component('laboratory/results/index')->where('results.data.0.id', $result->id));

    // The supervisor (not the entering technician) reviews it.
    $review = $this->actingAs($supervisor)->get(route('laboratory.results.show', $result));
    $review->assertOk();
    $review->assertInertia(fn ($page) => $page
        ->component('laboratory/results/show')
        ->where('result.patient.id', $ctx['patient']->id)
        ->where('result.status', 'pending_verification'));

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $result))->assertRedirect();
    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_UNDER_REVIEW);
    expect($result->fresh()->reviewed_by)->toBe($supervisor->id);

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.verify', $result->fresh()), ['verification_comment' => 'Confirmed against repeat.'])
        ->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_VERIFIED);
    expect($result->verified_by)->toBe($supervisor->id);
    expect($result->verified_at)->not->toBeNull();

    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $result->fresh()))->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
    expect($result->authorized_by)->toBe($supervisor->id);
    expect($result->isReadyForRelease())->toBeTrue();

    // The order/patient/sample/test identity is fully traceable.
    expect($result->item->laboratoryOrder->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($result->laboratory_sample_id)->toBe($sample->id);

    // Full audit trail across the entire pathway.
    foreach ([
        'laboratory_order_created', 'laboratory_sample_created', 'laboratory_sample_received',
        'laboratory_sample_accepted', 'laboratory_sample_processing_completed',
        'laboratory_result_created', 'laboratory_result_submitted',
        'laboratory_result_review_started', 'laboratory_result_verified', 'laboratory_result_authorized',
        'laboratory_result_status_changed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

/**
 * The correction branch (spec §44): Pending Verification -> Return for
 * Correction -> Result Correction -> Resubmit -> Verify -> Authorize.
 */
test('a result returned for correction is corrected, resubmitted, verified, and authorized', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric', 'unit' => 'mg/dL']);
    $ctx = pendingVerificationForItem($test, ['value_numeric' => '999']);
    $supervisor = userWithRole('laboratory-supervisor');
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_UNDER_REVIEW);

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $ctx['result']->fresh()), ['return_reason' => 'incorrect_value'])
        ->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION);
    expect($result->returned_by)->toBe($supervisor->id);
    expect($result->return_reason)->toBe('incorrect_value');

    // The result-entry technician sees it as editable again and corrects it.
    $show = $this->actingAs($technician)->get(route('laboratory.results.show', $result));
    $show->assertOk();

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => '95'])
        ->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_DRAFT);
    expect($result->value_numeric)->toBe('95.0000');

    $this->actingAs($technician)->post(route('laboratory.results.submit', $result))->assertRedirect();
    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);

    // Second pass through review -> verify -> authorize.
    $this->actingAs($supervisor)->post(route('laboratory.results.review', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $result->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $result->fresh()))->assertRedirect();

    $result = $result->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
    expect($result->isReadyForRelease())->toBeTrue();

    foreach ([
        'laboratory_result_returned_for_correction', 'laboratory_result_updated',
        'laboratory_result_submitted', 'laboratory_result_verified', 'laboratory_result_authorized',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action, 'auditable_id' => $result->id]);
    }
});
