<?php

use App\Models\Hospital;
use App\Models\Staff;

/**
 * Phase 7, Step 59 — cashier session authorization: guest redirect, wrong
 * role, accountant and hospital-admin access, session ownership enforcement.
 */
test('guest is redirected away from cashier session routes', function () {
    $hospital = Hospital::factory()->create();

    $this->get(route('billing.cashier.index'))->assertRedirect(route('login'));
    $this->get(route('billing.cashier.create'))->assertRedirect(route('login'));
    $this->post(route('billing.cashier.store'))->assertRedirect(route('login'));
});

test('a user without billing-cashier permissions gets 403 on cashier routes', function () {
    $user = userWithRole('doctor');

    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->get(route('billing.cashier.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.cashier.create'))->assertForbidden();
    $this->actingAs($user)->post(route('billing.cashier.store'), [
        'hospital_id' => $hospital->id,
        'opening_balance' => '5000',
    ])->assertForbidden();
});

test('an accountant can open a session and see the session list', function () {
    $hospital = Hospital::factory()->create();
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)
        ->get(route('billing.cashier.index'))
        ->assertOk();

    $response = $this->actingAs($accountant)
        ->post(route('billing.cashier.store'), [
            'hospital_id' => $hospital->id,
            'opening_balance' => '10000',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('cashier_sessions', [
        'cashier_id' => $accountant->id,
        'hospital_id' => $hospital->id,
        'status' => 'open',
    ]);
});

test('a hospital-admin can only open a session at their own hospital', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();

    $admin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $hospital1->id]);

    // Own hospital — allowed
    $response = $this->actingAs($admin)
        ->post(route('billing.cashier.store'), [
            'hospital_id' => $hospital1->id,
            'opening_balance' => '5000',
        ]);
    $response->assertRedirect();
    $this->assertDatabaseHas('cashier_sessions', ['cashier_id' => $admin->id, 'hospital_id' => $hospital1->id]);

    // Different hospital — forbidden
    $this->actingAs($admin)
        ->post(route('billing.cashier.store'), [
            'hospital_id' => $hospital2->id,
            'opening_balance' => '5000',
        ])
        ->assertForbidden();
});

test('only the session owner can close their session', function () {
    $hospital = Hospital::factory()->create();

    $cashier = userWithRole('accountant');
    $otherAccountant = userWithRole('accountant');

    $session = cashierSessionFor($hospital, $cashier);

    // Owner can view the close action page
    $this->actingAs($cashier)
        ->get(route('billing.cashier.show', $session->id))
        ->assertOk();

    // Another accountant cannot close it
    $this->actingAs($otherAccountant)
        ->post(route('billing.cashier.close', $session->id), [
            'actual_closing_balance' => '50000',
        ])
        ->assertForbidden();

    // Owner can close it
    $this->actingAs($cashier)
        ->post(route('billing.cashier.close', $session->id), [
            'actual_closing_balance' => '50000',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('cashier_sessions', [
        'id' => $session->id,
        'status' => 'closed',
    ]);
});
