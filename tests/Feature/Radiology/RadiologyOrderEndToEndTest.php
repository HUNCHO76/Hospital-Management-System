<?php

use App\Models\ClinicalEncounter;
use App\Models\RadiologyOrder;
use App\Models\ReceptionVisit;

/**
 * The full Phase 3 -> Radiology handoff (Phase 5, Step 36, Part 38): Patient
 * -> Reception -> Clinical Encounter -> Triage -> Consultation -> (no
 * diagnosis, proving the same urgent-care exception Laboratory has) ->
 * Clinical Decision -> Select Radiology -> Select Procedure(s) -> Enter
 * Clinical Indication -> Review -> Submit Radiology Order -> Radiology
 * Worklist -> Order Detail -> item cancel -> order cancel. Ends at
 * RADIOLOGY ORDERED — no scheduling/examination/findings/reporting/
 * verification/release/billing/NHIF workflow is exercised here, mirroring
 * LaboratoryOrderEndToEndTest exactly.
 */
test('a radiology order flows from clinical decision through to the worklist and detail page', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $procedureA = activeRadiologyProcedure(['code' => 'XRAY_CHEST', 'name' => 'X-Ray Chest']);
    $procedureB = activeRadiologyProcedure(['code' => 'USS_ABDOMEN', 'name' => 'Ultrasound Abdomen']);

    // Reception -> Clinical Encounter -> Triage -> Consultation.
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

    // Clinical Decision -> Radiology -> select procedures -> clinical
    // indication -> submit. No diagnosis exists yet, proving the same
    // urgent-imaging exception Laboratory has.
    expect($encounter->fresh()->diagnoses()->exists())->toBeFalse();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.radiology-orders.store', $encounter), [
        'procedure_ids' => [$procedureA->id, $procedureB->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Persistent cough and abdominal pain',
    ])->assertRedirect();

    $order = RadiologyOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    expect($order->status)->toBe(RadiologyOrder::STATUS_ORDERED);
    expect($order->items)->toHaveCount(2);

    // The encounter page itself shows the new radiology order inline.
    $encounterShow = $this->actingAs($doctor['user'])->get(route('clinical-encounters.show', $encounter));
    $encounterShow->assertOk();
    $encounterShow->assertInertia(fn ($page) => $page
        ->component('clinical-encounters/show')
        ->where('radiologyOrders.0.id', $order->id)
        ->has('radiologyOrders.0.items', 2));

    // Radiology worklist shows the order — viewed by radiology staff, who
    // hold radiology-orders.view (the ordering doctor only holds
    // create/update/cancel, mirroring Laboratory's own permission split).
    $technician = userWithRole('radiology-technician');
    $worklist = $this->actingAs($technician)->get(route('radiology.orders.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page
        ->component('radiology/orders/index')
        ->where('orders.data.0.id', $order->id)
        ->where('orders.data.0.patient.id', $ctx['patient']->id));

    // Radiology order detail.
    $detail = $this->actingAs($technician)->get(route('radiology.orders.show', $order));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('radiology/show')
        ->where('order.order_number', $order->order_number)
        ->where('order.items.0.procedure_code', 'XRAY_CHEST')
        ->where('order.items.1.procedure_code', 'USS_ABDOMEN')
        ->where('order.patient.id', $ctx['patient']->id)
        ->where('order.encounter.id', $encounter->id));

    // Remove one procedure, then cancel the whole order — RADIOLOGY
    // ORDERED is where this step ends; no scheduling/examination/result
    // workflow follows.
    $itemA = $order->items->firstWhere('procedure_code', 'XRAY_CHEST');
    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.radiology-orders.items.cancel', [$encounter, $order, $itemA]),
        ['cancellation_reason' => 'Duplicate order'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_ORDERED);

    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.radiology-orders.cancel', [$encounter, $order]),
        ['cancellation_reason' => 'Procedure no longer needed'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(RadiologyOrder::STATUS_CANCELLED);

    // Full audit trail.
    foreach ([
        'radiology_order_created',
        'radiology_order_item_added',
        'radiology_order_item_removed',
        'radiology_order_cancelled',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});
