<?php

use App\Services\DispensingService;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;

/**
 * Phase 6, Step 51, spec §19 — the patient's clinical/medication history
 * must distinguish prescribed, verified, dispensed, and partially-dispensed
 * prescriptions, never showing a partially-supplied prescription as fully
 * dispensed. This reuses PrescriptionVerification and
 * DispensingService::fulfillmentStatus() (the same authoritative sources
 * the dispensing worklist and Step 50 prescription report already use)
 * rather than a second, competing calculation.
 */
test('a freshly written prescription shows as pending verification and pending dispensing in patient history', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.id', $order->id)
        ->where('encounters.data.0.prescription_orders.0.verification_status', 'pending_verification')
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', 'pending'));
});

test('a verified-but-not-dispensed prescription shows as verified in patient history', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);
    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.verification_status', 'verified')
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', 'pending'));
});

test('a partially dispensed prescription never shows as fully dispensed in patient history', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx, ['items' => [[
        'medication_id' => activeMedication()->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 10,
    ]]]);
    receiveStock($order->items->sole()->medication, $ctx['hospital']);
    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $order->items->sole()->id, 'dispensed_quantity' => 4]],
    ], requestAs($pharmacist));

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', 'partially_dispensed'));
});

test('a fully dispensed prescription shows as dispensed in patient history', function () {
    $order = verifiedPrescriptionOrderFor();
    $item = $order->items->sole();
    $pharmacist = userWithRole('pharmacist');
    $dispensing = app(DispensingService::class)->start($order, requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => $item->quantity]],
    ], requestAs($pharmacist));

    $encounter = $order->clinicalEncounter;
    $patient = $encounter->patient;
    $doctor = $order->prescriber;

    $response = $this->actingAs($doctor)->get(route('patients.clinical-history', $patient));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', 'dispensed'));
});

test('a cancelled prescription shows cancelled status and no dispensing status in patient history', function () {
    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);
    app(PrescriptionOrderService::class)->cancel($order, 'no longer needed', requestAs($ctx['doctor']['user']));

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.prescription_orders.0.status', 'cancelled')
        ->where('encounters.data.0.prescription_orders.0.dispensing_status', null));
});
