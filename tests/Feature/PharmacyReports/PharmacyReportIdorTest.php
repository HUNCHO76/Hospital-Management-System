<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\DispensingService;
use App\Services\PrescriptionVerificationService;

/**
 * Facility isolation for the pharmacy reporting surface (Phase 6, Step 50,
 * spec §19/§31): a hospital-admin must never see another hospital's
 * prescriptions, dispensing, or stock in any report — enforced server-side
 * via each report's own scopeToHospital()-style method, never left to the
 * frontend to filter.
 */
test('the prescription report never leaks another hospital\'s prescriptions to a hospital-admin', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);

    $ctxB = diagnosisReadyEncounter();
    activePrescriptionOrderFor($ctxB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('pharmacy-reports.prescriptions'))
        ->assertInertia(fn ($page) => $page
            ->has('prescriptions.data', 1)
            ->where('prescriptions.data.0.id', $orderA->id));
});

test('the dispensing report never leaks another hospital\'s dispensing to a hospital-admin', function () {
    $ctxA = diagnosisReadyEncounter();
    $orderA = activePrescriptionOrderFor($ctxA);
    $verifierA = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($orderA, [], requestAs($verifierA));
    $dispensingA = app(DispensingService::class)->start($orderA, requestAs($verifierA));
    app(DispensingService::class)->complete($dispensingA, [
        'items' => [['prescription_order_item_id' => $orderA->items->sole()->id, 'dispensed_quantity' => 5]],
    ], requestAs($verifierA));

    $ctxB = diagnosisReadyEncounter();
    $orderB = activePrescriptionOrderFor($ctxB);
    $verifierB = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($orderB, [], requestAs($verifierB));
    $dispensingB = app(DispensingService::class)->start($orderB, requestAs($verifierB));
    app(DispensingService::class)->complete($dispensingB, [
        'items' => [['prescription_order_item_id' => $orderB->items->sole()->id, 'dispensed_quantity' => 5]],
    ], requestAs($verifierB));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('pharmacy-reports.dispensing'))
        ->assertInertia(fn ($page) => $page->has('items.data', 1));
});

test('the stock batch report never leaks another hospital\'s batches to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $medication = activeMedication();

    $batchA = receiveStock($medication, $hospitalA);
    receiveStock($medication, $hospitalB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy-reports.stock-batches'))
        ->assertInertia(fn ($page) => $page
            ->has('batches.data', 1)
            ->where('batches.data.0.id', $batchA->id));
});

test('the stock movement report never leaks another hospital\'s transactions to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $medication = activeMedication();

    receiveStock($medication, $hospitalA);
    receiveStock($medication, $hospitalB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy-reports.stock-movements'))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('the dashboard metrics themselves are scoped to a hospital-admin\'s own hospital', function () {
    $ctxA = diagnosisReadyEncounter();
    activePrescriptionOrderFor($ctxA);

    $ctxB = diagnosisReadyEncounter();
    activePrescriptionOrderFor($ctxB);
    activePrescriptionOrderFor($ctxB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('pharmacy.index'))
        ->assertInertia(fn ($page) => $page->where('metrics.prescriptions.today', 1));
});
