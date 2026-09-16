<?php

use App\Models\Hospital;
use App\Models\User;

/**
 * Permission gates for the stock module (Phase 6, Step 49, spec §30/§40):
 * every route requires authentication and the specific pharmacy-stock.*
 * ability — 'view' alone does not imply 'receive'/'adjust'/'quarantine'/
 * 'release'.
 */
test('a guest is redirected to login for every stock route', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);

    $this->get(route('pharmacy-stock.index'))->assertRedirect(route('login'));
    $this->get(route('pharmacy-stock.items.index'))->assertRedirect(route('login'));
    $this->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertRedirect(route('login'));
    $this->get(route('pharmacy-stock.receive.create'))->assertRedirect(route('login'));
    $this->post(route('pharmacy-stock.receive'))->assertRedirect(route('login'));
    $this->post(route('pharmacy-stock.batches.adjust', $batch))->assertRedirect(route('login'));
    $this->post(route('pharmacy-stock.batches.quarantine', $batch))->assertRedirect(route('login'));
    $this->post(route('pharmacy-stock.batches.release', $batch))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or act on stock', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('pharmacy-stock.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy-stock.items.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy-stock.receive.create'))->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.adjust', $batch), ['type' => 'adjustment_in', 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.quarantine', $batch), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.release', $batch))->assertForbidden();
});

test('a doctor holds no stock permission and cannot view or act on stock', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('pharmacy-stock.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertForbidden();
});

test('a pharmacist can view the dashboard, list, and detail, receive stock, adjust, quarantine, and release', function () {
    $hospital = Hospital::factory()->create();
    $batch = receiveStock(activeMedication(), $hospital, 100);
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy-stock.index'))->assertOk();
    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.index'))->assertOk();
    $this->actingAs($pharmacist)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertOk();
    $this->actingAs($pharmacist)->get(route('pharmacy-stock.receive.create'))->assertOk();

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.adjust', $batch), [
        'type' => 'adjustment_out', 'quantity' => 10, 'reason' => 'Count correction',
    ])->assertRedirect();

    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.quarantine', $batch), ['reason' => 'x'])->assertRedirect();
    $this->actingAs($pharmacist)->post(route('pharmacy-stock.batches.release', $batch))->assertRedirect();
});

test('a user with only pharmacy-stock.view cannot receive, adjust, quarantine, or release', function () {
    $hospital = Hospital::factory()->create();
    $batch = receiveStock(activeMedication(), $hospital, 100);
    seedRolesAndPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo('pharmacy-stock.view');

    $this->actingAs($user)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertOk();

    // A fully valid payload, so this actually exercises the authorization
    // check rather than being rejected by validation first.
    $this->actingAs($user)->post(route('pharmacy-stock.receive'), [
        'hospital_id' => $hospital->id,
        'medication_id' => activeMedication()->id,
        'batch_number' => 'X-999',
        'expiry_date' => now()->addYear()->toDateString(),
        'quantity' => 10,
    ])->assertForbidden();

    $this->actingAs($user)->post(route('pharmacy-stock.batches.adjust', $batch), ['type' => 'adjustment_in', 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.quarantine', $batch), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.release', $batch))->assertForbidden();
});

test('a super-admin bypasses stock permission checks and hospital scoping', function () {
    $batch = receiveStock(activeMedication(), Hospital::factory()->create(), 100);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('pharmacy-stock.index'))->assertOk();
    $this->actingAs($user)->get(route('pharmacy-stock.items.show', $batch->stock_item_id))->assertOk();
    $this->actingAs($user)->post(route('pharmacy-stock.batches.quarantine', $batch), ['reason' => 'x'])->assertRedirect();
});
