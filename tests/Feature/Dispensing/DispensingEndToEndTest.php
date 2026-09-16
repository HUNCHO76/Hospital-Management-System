<?php

use App\Models\ClinicalEncounter;
use App\Models\Dispensing;
use App\Models\PrescriptionOrder;
use App\Models\ReceptionVisit;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;

/**
 * The full Step 46 -> 47 -> 48 handoff (spec §38): Doctor creates
 * prescription -> submitted -> pharmacist verifies -> VERIFIED -> pharmacy
 * opens the dispensing queue -> opens the prescription -> patient identity
 * reviewed -> quantities entered -> dispensing completed -> prescription
 * fulfillment becomes DISPENSED -> patient history shows the fulfillment.
 * Ends at Step 48's own boundary — no stock/billing/NHIF workflow is
 * exercised here.
 */
test('a prescription flows from creation through verification to full dispensing and appears in patient history', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $medication = activeMedication(['generic_name' => 'Amoxicillin', 'code' => 'AMOX-500']);
    // Phase 6, Step 49: dispensing now deducts real stock, so this
    // medication needs some on hand before the pharmacist can complete
    // dispensing later in this scenario.
    receiveStock($medication, $ctx['hospital'], 100);

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

    // Doctor creates the prescription.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.prescription-orders.store', $encounter), [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '500', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'duration' => 7, 'duration_unit' => 'days', 'quantity' => 14],
        ],
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $item = $order->items->sole();

    // Pharmacist verifies.
    $pharmacist = userWithRole('pharmacist');
    $this->actingAs($pharmacist)->post(route('pharmacy-verification.verify', $order))->assertRedirect();
    expect($order->fresh()->verification->status)->toBe('verified');

    // Dispensing queue shows it as awaiting dispensing.
    $queue = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.index'));
    $queue->assertOk();
    $queue->assertInertia(fn ($page) => $page
        ->component('pharmacy/dispensing/index')
        ->where('prescriptions.data.0.id', $order->id)
        ->where('prescriptions.data.0.fulfillment_status', 'pending'));

    // Pharmacist opens the prescription — patient identity is shown.
    $detail = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.show', $order));
    $detail->assertOk();
    $detail->assertInertia(fn ($page) => $page
        ->component('pharmacy/dispensing/show')
        ->where('prescription.prescription_number', $order->prescription_number)
        ->where('prescription.patient.patient_number', $ctx['patient']->patient_number)
        ->where('prescription.items.0.remaining_quantity', 14));

    // Start dispensing, enter quantities, complete.
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $dispensing = Dispensing::query()->where('prescription_order_id', $order->id)->sole();

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 14]],
        'notes' => 'Patient counselled on administration.',
    ])->assertRedirect();

    expect($dispensing->fresh()->status)->toBe(Dispensing::STATUS_COMPLETED);

    // Dispensing detail now shows the prescription as fully dispensed.
    $finalDetail = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.show', $order));
    $finalDetail->assertInertia(fn ($page) => $page
        ->where('prescription.fulfillment_status', 'dispensed')
        ->where('prescription.items.0.dispensed_quantity', 14)
        ->where('prescription.items.0.remaining_quantity', 0)
        ->where('prescription.dispensings.0.dispensing_number', $dispensing->dispensing_number));

    // Full audit trail.
    foreach ([
        'prescription_order_created',
        'prescription_submitted',
        'prescription_verified',
        'dispensing_started',
        'dispensing_item_added',
        'dispensing_completed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }
});

/**
 * Partial dispensing across two separate events (spec §38's second
 * scenario): 30 tablets prescribed, 20 dispensed now, 10 dispensed later.
 */
test('a prescription moves from partially dispensed to fully dispensed across two separate dispensing events', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    // Phase 6, Step 49: dispensing now deducts real stock — provision enough
    // for both dispensing events in this scenario.
    receiveStock($medication, $ctx['hospital'], 100);
    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 30]],
    ], requestAs($ctx['doctor']['user']));
    $item = $order->items->sole();

    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 20]],
    ])->assertRedirect();

    $afterFirst = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.show', $order));
    $afterFirst->assertInertia(fn ($page) => $page
        ->where('prescription.fulfillment_status', 'partially_dispensed')
        ->where('prescription.items.0.dispensed_quantity', 20)
        ->where('prescription.items.0.remaining_quantity', 10));

    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.start', $order))->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-dispensing.complete', $order), [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 10]],
    ])->assertRedirect();

    $afterSecond = $this->actingAs($pharmacist)->get(route('pharmacy-dispensing.show', $order));
    $afterSecond->assertInertia(fn ($page) => $page
        ->where('prescription.fulfillment_status', 'dispensed')
        ->where('prescription.items.0.remaining_quantity', 0)
        ->has('prescription.dispensings', 2));
});
