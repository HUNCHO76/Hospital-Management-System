<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\ReceptionVisit;

/**
 * The full Phase 3 -> Laboratory handoff (Step 30, spec §17/44): Reception ->
 * Clinical Encounter -> Triage -> Consultation -> (no diagnosis, proving
 * spec §17's urgent-care exception) -> Laboratory Order with multiple tests
 * (alongside a medication order, proving the parallel/independent orders
 * boundary from Step 27 still holds) -> Laboratory worklist -> Laboratory
 * Order Detail -> item cancel -> order cancel. No sample/processing/result
 * workflow is exercised here — the order simply has to arrive correctly.
 */
test('an encounter with an engaged consultation but no diagnosis can still produce a laboratory order that reaches the worklist and detail page', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $labTestA = activeLaboratoryTest(['name' => 'Full Blood Count', 'code' => 'FBC']);
    $labTestB = activeLaboratoryTest(['name' => 'Malaria RDT', 'code' => 'MRDT']);

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
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();

    expect($encounter->fresh()->diagnoses()->exists())->toBeFalse();

    // Laboratory order with two tests, placed before any diagnosis exists.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.laboratory-orders.store', $encounter), [
        'test_ids' => [$labTestA->id, $labTestB->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Fever, suspected malaria',
    ])->assertRedirect();

    // A treatment plan + medication order, placed independently — proves
    // Step 27's parallel-orders boundary still holds with Laboratory as its
    // own sibling branch.
    $diagnosisCode = activeDiagnosisCode(['code' => 'B50.9', 'description' => 'Malaria']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.store', $encounter))->assertRedirect();
    $medication = activeMedication(['name' => 'Artemether-Lumefantrine']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'medication',
        'description' => 'Antimalarial course',
        'prescription' => ['medication_id' => $medication->id],
    ])->assertRedirect();

    $order = LaboratoryOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
    expect($order->priority)->toBe('urgent');
    expect($order->items)->toHaveCount(2);

    // Laboratory worklist.
    $technician = userWithRole('laboratory-technician');
    $worklist = $this->actingAs($technician)->get(route('laboratory.orders.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page
        ->component('laboratory/orders/index')
        ->where('orders.data.0.id', $order->id)
        ->where('orders.data.0.patient.id', $ctx['patient']->id));

    // Laboratory order detail.
    $detail = $this->actingAs($technician)->get(route('laboratory.orders.show', $order));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('laboratory/show')
        ->where('order.order_number', $order->order_number)
        ->where('order.items.0.test_code', 'FBC')
        ->where('order.items.1.test_code', 'MRDT')
        ->where('order.patient.id', $ctx['patient']->id)
        ->where('order.encounter.id', $encounter->id));

    // Remove one item, then cancel the whole order.
    $item = $order->items->firstWhere('test_code', 'MRDT');
    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.laboratory-orders.items.cancel', [$encounter, $order, $item]),
        ['cancellation_reason' => 'Rapid test unavailable'],
    )->assertRedirect();
    expect($item->fresh()->status)->toBe('cancelled');
    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_ORDERED);

    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.laboratory-orders.cancel', [$encounter, $order]),
        ['cancellation_reason' => 'Sample unavailable'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(LaboratoryOrder::STATUS_CANCELLED);

    // Full audit trail.
    foreach ([
        'laboratory_order_created',
        'laboratory_order_item_added',
        'laboratory_order_item_removed',
        'laboratory_order_cancelled',
        'prescription_created',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});
