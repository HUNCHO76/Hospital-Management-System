<?php

use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Models\ReceptionVisit;

/**
 * The full Phase 3 -> Prescription handoff (Phase 6, Step 46): Patient ->
 * Reception -> Clinical Encounter -> Triage -> Consultation -> (no
 * diagnosis, proving the same urgent-care exception Laboratory/Radiology
 * have) -> Clinical Decision -> Select Prescription -> Search Medication ->
 * Add Medication -> Enter dose/frequency/route/duration/quantity -> Add
 * Second Medication -> Review -> Submit -> Prescription Detail -> Pharmacy
 * Worklist -> Patient Clinical History. Ends at PRESCRIPTION ACTIVE — no
 * verification/dispensing/stock/billing/NHIF workflow is exercised here,
 * mirroring RadiologyOrderEndToEndTest exactly.
 */
test('a prescription flows from clinical decision through to the pharmacy worklist and patient history', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $paracetamol = activeMedication(['code' => 'PARA-500', 'generic_name' => 'Paracetamol', 'strength' => '500mg', 'form' => 'tablet']);
    $amoxicillin = activeMedication(['code' => 'AMOX-500', 'generic_name' => 'Amoxicillin', 'strength' => '500mg', 'form' => 'capsule']);

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

    // Clinical Decision -> Prescription -> search/select medications ->
    // dose/frequency/route/duration/quantity -> add second medication ->
    // review -> submit. No diagnosis exists yet.
    expect($encounter->fresh()->diagnoses()->exists())->toBeFalse();

    // Unauthorized user (nurse) attempting to write a prescription is denied.
    $this->actingAs($nurse)->post(route('clinical-encounters.prescription-orders.store', $encounter), [
        'items' => [['medication_id' => $paracetamol->id, 'dose' => '1', 'frequency' => 'three_times_daily', 'route' => 'oral', 'quantity' => 15]],
    ])->assertForbidden();

    // An inactive medication is never selectable for a new prescription.
    $inactiveMedication = activeMedication(['status' => 'inactive']);
    $searchResponse = $this->actingAs($doctor['user'])->getJson(route('medications.search-json', ['search' => $inactiveMedication->generic_name]));
    $searchResponse->assertOk();
    expect(collect($searchResponse->json('data'))->pluck('id'))->not->toContain($inactiveMedication->id);

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.prescription-orders.store', $encounter), [
        'items' => [
            ['medication_id' => $paracetamol->id, 'dose' => '1', 'dose_unit' => 'tablet', 'frequency' => 'three_times_daily', 'route' => 'oral', 'duration' => 5, 'duration_unit' => 'days', 'quantity' => 15, 'instructions' => 'Take after meals'],
            ['medication_id' => $amoxicillin->id, 'dose' => '500', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'duration' => 7, 'duration_unit' => 'days', 'quantity' => 14],
        ],
        'clinical_notes' => 'Suspected bacterial infection',
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $order->load('items');
    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
    expect($order->items)->toHaveCount(2);

    // The encounter page itself shows the new prescription inline.
    $encounterShow = $this->actingAs($doctor['user'])->get(route('clinical-encounters.show', $encounter));
    $encounterShow->assertOk();
    $encounterShow->assertInertia(fn ($page) => $page
        ->component('clinical-encounters/show')
        ->where('prescriptionOrders.0.id', $order->id)
        ->has('prescriptionOrders.0.items', 2));

    // Pharmacy worklist shows the prescription — viewed by pharmacy staff,
    // who hold pharmacy-prescriptions.view (the prescribing doctor only
    // holds create/update/cancel, mirroring Radiology/Laboratory's own
    // permission split).
    $pharmacist = userWithRole('pharmacist');
    $worklist = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index'));
    $worklist->assertOk();
    $worklist->assertInertia(fn ($page) => $page
        ->component('pharmacy/prescriptions/index')
        ->where('prescriptions.data.0.id', $order->id)
        ->where('prescriptions.data.0.patient.id', $ctx['patient']->id));

    // Prescription detail page.
    $detail = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.show', $order));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('pharmacy/prescriptions/show')
        ->where('prescription.prescription_number', $order->prescription_number)
        ->where('prescription.items.0.medication_code_snapshot', 'PARA-500')
        ->where('prescription.items.1.medication_code_snapshot', 'AMOX-500')
        ->where('prescription.patient.id', $ctx['patient']->id)
        ->where('prescription.encounter.id', $encounter->id));

    // Remove one medication, then cancel the whole prescription —
    // PRESCRIPTION ACTIVE is where this step ends; no verification/
    // dispensing workflow follows.
    $itemA = $order->items->firstWhere('medication_code_snapshot', 'PARA-500');
    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.prescription-orders.items.cancel', [$encounter, $order, $itemA]),
        ['cancellation_reason' => 'Duplicate entry'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);

    $this->actingAs($doctor['user'])->post(
        route('clinical-encounters.prescription-orders.cancel', [$encounter, $order]),
        ['cancellation_reason' => 'Patient allergic reaction discovered'],
    )->assertRedirect();

    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_CANCELLED);

    // Patient clinical history shows the prescription.
    $history = $this->actingAs($doctor['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $history->assertOk();
    $history->assertInertia(fn ($page) => $page
        ->component('patients/clinical-history')
        ->where('encounters.data.0.prescription_orders.0.id', $order->id)
        ->where('encounters.data.0.prescription_orders.0.status', 'cancelled'));

    // Full audit trail.
    foreach ([
        'prescription_order_created',
        'prescription_order_item_added',
        'prescription_order_item_removed',
        'prescription_order_cancelled',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});
