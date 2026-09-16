<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\User;

/**
 * IDOR protection for the stock surface (Phase 6, Step 49, spec §31):
 * changing the stock item/batch id in the URL must never expose or allow
 * acting on another hospital's inventory for a hospital-admin — the one role
 * this application actually fences by hospital (mirrors
 * PrescriptionVerificationIdorTest/DispensingAuthorizationTest's own
 * "hospital-admin acts within their own hospital only" precedent exactly).
 */
test('a hospital-admin cannot view or act on another hospital\'s stock by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $batchA = receiveStock(activeMedication(), $hospitalA, 100);
    $batchB = receiveStock(activeMedication(), $hospitalB, 100);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy-stock.items.show', $batchA->stock_item_id))->assertOk();
    $this->actingAs($adminA)->get(route('pharmacy-stock.items.show', $batchB->stock_item_id))->assertForbidden();

    $this->actingAs($adminA)->post(route('pharmacy-stock.batches.adjust', $batchB), [
        'type' => 'adjustment_out', 'quantity' => 1, 'reason' => 'x',
    ])->assertForbidden();
    $this->actingAs($adminA)->post(route('pharmacy-stock.batches.quarantine', $batchB), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($adminA)->post(route('pharmacy-stock.batches.release', $batchB))->assertForbidden();

    expect($batchB->fresh()->quantity)->toBe(100);
    expect($batchB->fresh()->status)->toBe(StockBatch::STATUS_ACTIVE);
});

test('a hospital-admin cannot receive stock into another hospital by submitting its id directly', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $medication = activeMedication();

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);
    $adminA->givePermissionTo('pharmacy-stock.receive');

    $this->actingAs($adminA)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospitalB->id,
        'medication_id' => $medication->id,
        'batch_number' => 'X-001',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 100,
    ])->assertForbidden();

    expect(StockItem::query()->where('hospital_id', $hospitalB->id)->exists())->toBeFalse();
});

test('the stock item list never leaks another hospital\'s inventory to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $batchA = receiveStock(activeMedication(), $hospitalA, 100);
    receiveStock(activeMedication(), $hospitalB, 100);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('pharmacy-stock.items.index'))->assertInertia(fn ($page) => $page
        ->has('items.data', 1)
        ->where('items.data.0.id', $batchA->stock_item_id));
});

test('a plain pharmacist (not hospital-admin) is unrestricted across hospitals, matching this app\'s existing systemic precedent', function () {
    $hospitalA = Hospital::factory()->create();
    $batchA = receiveStock(activeMedication(), $hospitalA, 100);
    $pharmacist = userWithRole('pharmacist');

    // This is not a gap introduced by Step 49 — it mirrors
    // PrescriptionOrderPolicy::belongsToUsersHospital()'s own documented
    // precedent that only hospital-admin is hospital-scoped by policy.
    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.show', $batchA->stock_item_id))->assertOk();
});
