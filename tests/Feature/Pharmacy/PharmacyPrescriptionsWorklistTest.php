<?php

use App\Services\PrescriptionOrderService;

/**
 * The cross-encounter prescriptions worklist (Phase 6, Step 44, extended in
 * Step 46 to read the first-class PrescriptionOrder table): every
 * prescription already lives on its own authoritative PrescriptionOrder/
 * PrescriptionOrderItem row; this worklist is purely an aggregated,
 * filterable view across every clinical encounter, not a duplicated
 * pharmacy-owned copy.
 */
test('the worklist shows a prescription with its full clinical detail', function () {
    $pharmacist = userWithRole('pharmacist');
    $order = activePrescriptionOrderFor();

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('pharmacy/prescriptions/index')
        ->has('prescriptions.data', 1)
        ->where('prescriptions.data.0.id', $order->id)
        ->where('prescriptions.data.0.items.0.dose', '1 tablet')
        ->where('prescriptions.data.0.items.0.route', 'oral')
        ->where('prescriptions.data.0.items.0.frequency', 'three_times_daily')
        ->has('prescriptions.data.0.patient.patient_number')
        ->has('prescriptions.data.0.encounter.encounter_number'));
});

test('the worklist search filters by medication name', function () {
    $pharmacist = userWithRole('pharmacist');

    $paracetamol = activeMedication(['generic_name' => 'Paracetamol']);
    $amoxicillin = activeMedication(['generic_name' => 'Amoxicillin']);

    activePrescriptionOrderFor(null, ['items' => [['medication_id' => $paracetamol->id, 'dose' => '1 tablet', 'frequency' => 'three_times_daily', 'route' => 'oral', 'quantity' => 10]]]);
    activePrescriptionOrderFor(null, ['items' => [['medication_id' => $amoxicillin->id, 'dose' => '1 tablet', 'frequency' => 'three_times_daily', 'route' => 'oral', 'quantity' => 10]]]);

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index', ['search' => 'Paracetamol']));

    $response->assertInertia(fn ($page) => $page
        ->has('prescriptions.data', 1)
        ->where('prescriptions.data.0.items.0.medication_name_snapshot', 'Paracetamol'));
});

test('the worklist search filters by patient number', function () {
    $pharmacist = userWithRole('pharmacist');
    $order = activePrescriptionOrderFor();
    activePrescriptionOrderFor();

    $patientNumber = $order->clinicalEncounter->patient->patient_number;

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index', ['search' => $patientNumber]));

    $response->assertInertia(fn ($page) => $page->has('prescriptions.data', 1));
});

test('a cancelled prescription order still appears on the worklist but not as active', function () {
    $pharmacist = userWithRole('pharmacist');

    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    app(PrescriptionOrderService::class)->cancel($order, 'Discontinued', requestAs($ctx['doctor']['user']));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('prescriptions.data', 1)
        ->where('prescriptions.data.0.status', 'cancelled'));

    $filtered = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index', ['status' => 'active']));
    $filtered->assertInertia(fn ($page) => $page->has('prescriptions.data', 0));
});

test('the worklist is paginated and sorted by most recently prescribed first', function () {
    $pharmacist = userWithRole('pharmacist');

    $older = activePrescriptionOrderFor();
    $older->update(['prescribed_at' => now()->subDays(2)]);

    $newer = activePrescriptionOrderFor();
    $newer->update(['prescribed_at' => now()->subHour()]);

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('prescriptions.data.0.id', $newer->id)
        ->where('prescriptions.data.1.id', $older->id));
});
