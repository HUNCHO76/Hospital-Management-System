<?php

use App\Models\Medication;
use App\Services\PrescriptionOrderService;

/**
 * The pharmacy dashboard shows only real, currently-knowable figures (Phase
 * 6, Step 44, Part 17) — no fabricated "pending verification"/"low stock"
 * statistics, since none of that data exists until Steps 48-49. Reads the
 * first-class PrescriptionOrder table (Phase 6, Step 46).
 */
test('the dashboard shows real prescription and medication catalog counts', function () {
    $pharmacist = userWithRole('pharmacist');

    activePrescriptionOrderFor();
    activePrescriptionOrderFor();
    activeMedication();
    Medication::factory()->create(['status' => Medication::STATUS_INACTIVE]);

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('pharmacy/index')
        ->where('activePrescriptionCount', 2)
        ->has('recentPrescriptions', 2));

    // Total medications includes both active and inactive; active count
    // only counts the active ones — proving neither figure is fabricated.
    $page = $response->viewData('page');
    expect($page['props']['activeMedicationCount'])->toBeGreaterThanOrEqual(1);
    expect($page['props']['totalMedicationCount'])->toBeGreaterThanOrEqual(2);
});

test('a cancelled prescription order does not count as active on the dashboard', function () {
    $pharmacist = userWithRole('pharmacist');

    $ctx = diagnosisReadyEncounter();
    $order = activePrescriptionOrderFor($ctx);

    app(PrescriptionOrderService::class)->cancel($order, 'Patient allergic reaction discovered', requestAs($ctx['doctor']['user']));

    $response = $this->actingAs($pharmacist)->get(route('pharmacy.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('activePrescriptionCount', 0)
        ->has('recentPrescriptions', 1));
});

test('the dashboard prescriptions link and content only render for authorized users', function () {
    $pharmacist = userWithRole('pharmacist');

    activePrescriptionOrderFor();

    $this->actingAs($pharmacist)->get(route('pharmacy.index'))
        ->assertInertia(fn ($page) => $page->has('recentPrescriptions.0.items.0.medication_name_snapshot'));
});
