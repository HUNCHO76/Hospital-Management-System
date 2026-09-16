<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

/**
 * Phase 7, Step 59 — cashier session security: IDOR between hospitals,
 * mass-assignment protection, tampered session ownership.
 */
test('a hospital-admin cannot view a session belonging to a different hospital', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();

    $cashier = User::factory()->create();
    $session = cashierSessionFor($hospital2, $cashier);

    $admin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $hospital1->id]);

    $this->actingAs($admin)
        ->get(route('billing.cashier.show', $session->id))
        ->assertForbidden();
});

test('a hospital-admin cannot close a session at a different hospital even if they hold billing-cashier.close', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();

    $cashierAtH2 = User::factory()->create();
    $session = cashierSessionFor($hospital2, $cashierAtH2);

    $adminAtH1 = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminAtH1->id, 'hospital_id' => $hospital1->id]);

    $this->actingAs($adminAtH1)
        ->post(route('billing.cashier.close', $session->id), [
            'actual_closing_balance' => '50000',
        ])
        ->assertForbidden();
});

test('mass-assigning hospital_id or cashier_id on the store endpoint is ignored', function () {
    $hospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $accountant = userWithRole('accountant');
    $victimUser = User::factory()->create();

    // Even if cashier_id or hospital_id are in the payload, the service uses
    // the authenticated user's id and the validated hospital_id.
    $this->actingAs($accountant)
        ->post(route('billing.cashier.store'), [
            'hospital_id' => $hospital->id,
            'opening_balance' => '5000',
            'cashier_id' => $victimUser->id,  // attempt to impersonate
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('cashier_sessions', [
        'cashier_id' => $accountant->id,  // never the tampered value
        'hospital_id' => $hospital->id,
    ]);
    $this->assertDatabaseMissing('cashier_sessions', [
        'cashier_id' => $victimUser->id,
    ]);
});

test('an accountant cannot close a session they did not open', function () {
    $hospital = Hospital::factory()->create();

    $cashier1 = userWithRole('accountant');
    $cashier2 = userWithRole('accountant');

    $session = cashierSessionFor($hospital, $cashier1);

    $this->actingAs($cashier2)
        ->post(route('billing.cashier.close', $session->id), [
            'actual_closing_balance' => '50000',
        ])
        ->assertForbidden();
});
