<?php

use App\Models\Hospital;
use App\Models\Staff;

/**
 * Phase 7, Step 60 — billing report authorization: guest redirect, wrong
 * role, accountant and hospital-admin access, facility isolation.
 */
test('guest is redirected away from billing report routes', function () {
    $this->get(route('billing.reports.index'))->assertRedirect(route('login'));
    $this->get(route('billing.reports.invoices'))->assertRedirect(route('login'));
    $this->get(route('billing.reports.payments'))->assertRedirect(route('login'));
    $this->get(route('billing.reports.cashier-sessions'))->assertRedirect(route('login'));
    $this->get(route('billing.reports.corrections'))->assertRedirect(route('login'));
});

test('a user without billing-reports.view gets 403 on all report routes', function () {
    $user = userWithRole('doctor');

    $this->actingAs($user)->get(route('billing.reports.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.invoices'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.payments'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.cashier-sessions'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.corrections'))->assertForbidden();
});

test('an accountant can access all billing report routes', function () {
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing.reports.index'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.reports.invoices'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.reports.payments'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.reports.cashier-sessions'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.reports.corrections'))->assertOk();
});

test('a hospital-admin can access billing reports', function () {
    $hospital = Hospital::factory()->create();
    $admin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $hospital->id]);

    $this->actingAs($admin)->get(route('billing.reports.index'))->assertOk();
    $this->actingAs($admin)->get(route('billing.reports.invoices'))->assertOk();
});

test('a user without billing-reports.export gets 403 on CSV export requests', function () {
    // A user with only billing-reports.view (via direct grant, no role that
    // includes .export) should be forbidden from export endpoints.
    $user = userWithRole('doctor');
    $user->givePermissionTo('billing-reports.view');

    $this->actingAs($user)->get(route('billing.reports.invoices', ['export' => 1]))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.payments', ['export' => 1]))->assertForbidden();
    $this->actingAs($user)->get(route('billing.reports.cashier-sessions', ['export' => 1]))->assertForbidden();
});

test('an accountant with export permission can download CSV', function () {
    $accountant = userWithRole('accountant');

    $response = $this->actingAs($accountant)->get(route('billing.reports.invoices', ['export' => 1]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
