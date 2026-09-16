<?php

use App\Models\ClinicalEncounter;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyReport;
use App\Models\ReceptionVisit;

/**
 * The master Phase 5 Radiology workflow (Step 43 — Integration & Testing):
 * the full chain across every Radiology module (Steps 36-44) in one place,
 * driven through real HTTP routes starting from Phase 2 reception (not a
 * factory shortcut), ending with the doctor's clinical encounter and the
 * patient's clinical history both reflecting two independently-traceable
 * released results. Every individual transition already has its own deep
 * unit/HTTP coverage in its own step's test suite — this is the single
 * authoritative "does the whole thing actually connect end to end" reference,
 * mirroring OpdWorkflowEndToEndTest's own role for Phase 3.
 *
 * Two procedures are ordered and carried through independently (Part 32 —
 * "support multiple results for one patient... do not overwrite one result
 * with another") to prove neither the scheduling, reception, examination,
 * reporting, verification, nor release layer ever conflates the two.
 */
test('a walk-in patient flows through the entire radiology chain to two independently released results, visible to the doctor and in patient history', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $chestXray = activeRadiologyProcedure(['code' => 'XR-CHEST-P5', 'name' => 'Chest X-Ray', 'modality' => 'x_ray']);
    $abdoUltrasound = activeRadiologyProcedure(['code' => 'USS-ABDO-P5', 'name' => 'Abdominal Ultrasound', 'modality' => 'ultrasound']);

    // 1-3: Reception check-in (Phase 2, real routes).
    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertRedirect();
    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    // 4-6: Clinical encounter, triage, vital signs (Phase 3).
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
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 82])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    // 7-9: Consultation and radiology order — deliberately no diagnosis yet,
    // proving the same urgent-imaging exception Laboratory has.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();
    expect($encounter->fresh()->diagnoses()->exists())->toBeFalse();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.radiology-orders.store', $encounter), [
        'procedure_ids' => [$chestXray->id, $abdoUltrasound->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Chest trauma with suspected internal injury',
    ])->assertRedirect();

    $order = RadiologyOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    expect($order->items)->toHaveCount(2);
    $chestItem = $order->items->firstWhere('procedure_code', 'XR-CHEST-P5');
    $abdoItem = $order->items->firstWhere('procedure_code', 'USS-ABDO-P5');

    // 10: Radiology scheduling — two separate appointments, distinct
    // resources/times, proving items are scheduled independently.
    $scheduler = userWithRole('radiology-technician');
    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $chestItem->id,
        'resource_name' => 'X-Ray Room P5-1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertRedirect();
    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $abdoItem->id,
        'resource_name' => 'Ultrasound Room P5-1',
        'scheduled_start' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(10, 30)->toDateTimeString(),
    ])->assertRedirect();

    $chestAppointment = RadiologyAppointment::query()->where('radiology_order_item_id', $chestItem->id)->sole();
    $abdoAppointment = RadiologyAppointment::query()->where('radiology_order_item_id', $abdoItem->id)->sole();
    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_SCHEDULED);

    // 11-13: Reception — arrival, identity/order verification, READY —
    // for both appointments independently.
    foreach ([$chestAppointment, $abdoAppointment] as $appointment) {
        $this->actingAs($scheduler)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();
        $this->actingAs($scheduler)->post(route('radiology-reception.verify', $appointment))->assertRedirect();
        $this->actingAs($scheduler)->post(route('radiology-reception.ready', $appointment))->assertRedirect();
    }
    expect($chestAppointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
    expect($abdoAppointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);

    // 14: Examination start -> complete, for both.
    $technician = userWithRole('radiology-technician');
    foreach ([$chestAppointment, $abdoAppointment] as $appointment) {
        $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();
        $this->actingAs($technician)->post(route('radiology-examinations.complete', $appointment))->assertRedirect();
    }
    expect($chestAppointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);
    expect($abdoAppointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);

    // 15-16: Findings, impression, report submitted — different content per
    // study, proving neither report clobbers the other.
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), ['radiology_appointment_id' => $chestAppointment->id])->assertRedirect();
    $chestReport = RadiologyReport::query()->where('radiology_appointment_id', $chestAppointment->id)->sole();
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $chestReport), [
        'findings' => 'No rib fractures. Lungs clear.',
        'impression' => 'No acute chest injury.',
    ])->assertRedirect();
    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $chestReport))->assertRedirect();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), ['radiology_appointment_id' => $abdoAppointment->id])->assertRedirect();
    $abdoReport = RadiologyReport::query()->where('radiology_appointment_id', $abdoAppointment->id)->sole();
    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $abdoReport), [
        'findings' => 'No free fluid. Liver, spleen, kidneys unremarkable.',
        'impression' => 'No evidence of internal injury.',
    ])->assertRedirect();
    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $abdoReport))->assertRedirect();

    expect($chestReport->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
    expect($abdoReport->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);

    // 17: Independent verification — an independent radiologist reviews and
    // verifies both.
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $chestReport))->assertRedirect();
    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $abdoReport))->assertRedirect();
    expect($chestReport->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
    expect($abdoReport->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);

    // 18: Release — the official clinical result.
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);
    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $chestReport))->assertRedirect();
    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $abdoReport))->assertRedirect();
    expect($chestReport->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);
    expect($abdoReport->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);

    // 19-20: The doctor opens the encounter and sees both released results,
    // each with its own distinct findings/impression — never conflated.
    $encounterShow = $this->actingAs($doctor['user'])->get(route('clinical-encounters.show', $encounter));
    $encounterShow->assertOk();
    $encounterShow->assertInertia(fn ($page) => $page
        ->component('clinical-encounters/show')
        ->has('radiologyResults', 2));

    $resultsPayload = $encounterShow->viewData('page')['props']['radiologyResults'];
    $findingsByProcedure = collect($resultsPayload)->pluck('findings', 'procedure_code');
    expect($findingsByProcedure['XR-CHEST-P5'])->toBe('No rib fractures. Lungs clear.');
    expect($findingsByProcedure['USS-ABDO-P5'])->toBe('No free fluid. Liver, spleen, kidneys unremarkable.');

    // 21: Patient history reflects both released results.
    $history = $this->actingAs($doctor['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $history->assertOk();
    $history->assertInertia(fn ($page) => $page->has('encounters.data.0.radiology_results', 2));

    // 22: No duplicate report was ever created for either examination, and
    // the two studies remain permanently distinguishable.
    expect(RadiologyReport::query()->where('radiology_appointment_id', $chestAppointment->id)->count())->toBe(1);
    expect(RadiologyReport::query()->where('radiology_appointment_id', $abdoAppointment->id)->count())->toBe(1);
    expect($chestReport->id)->not->toBe($abdoReport->id);

    // 23: The audit trail contains the major event across every stage of
    // the chain, for both studies.
    foreach ([
        'clinical_encounter_created', 'radiology_order_created',
        'radiology_appointment_scheduled', 'radiology_patient_arrived',
        'radiology_examination_started', 'radiology_examination_completed',
        'radiology_report_created', 'radiology_report_submitted',
        'radiology_report_verified', 'radiology_result_released',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    $this->assertDatabaseCount('radiology_reports', 2);
});
