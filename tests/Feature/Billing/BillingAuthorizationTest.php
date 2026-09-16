<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;

/**
 * Permission gates for the billing module (Phase 7, Step 52, spec §29-31):
 * every route requires authentication and the specific billing.* ability.
 */
test('a guest is redirected to login for every billing route', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);

    $this->get(route('billing.index'))->assertRedirect(route('login'));
    $this->get(route('billing.accounts.index'))->assertRedirect(route('login'));
    $this->get(route('billing.accounts.show', $charge->patient_account_id))->assertRedirect(route('login'));
    $this->get(route('billing.charges.create'))->assertRedirect(route('login'));
    $this->post(route('billing.charges.store'))->assertRedirect(route('login'));
    $this->post(route('billing.charges.post', $charge))->assertRedirect(route('login'));
    $this->post(route('billing.charges.void', $charge))->assertRedirect(route('login'));
    $this->post(route('billing.charges.cancel', $charge))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or act on billing', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.accounts.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.accounts.show', $charge->patient_account_id))->assertForbidden();
    $this->actingAs($user)->get(route('billing.charges.create'))->assertForbidden();
    $this->actingAs($user)->post(route('billing.charges.post', $charge))->assertForbidden();
});

test('a doctor holds no billing permission and cannot view or act on billing', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('billing.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.accounts.show', $charge->patient_account_id))->assertForbidden();
});

test('an accountant can view the dashboard, accounts, record a charge, and manage its lifecycle', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $service = serviceFor();
    priceFor($service, $hospital);

    $this->actingAs($accountant)->get(route('billing.index'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.accounts.index'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.charges.create'))->assertOk();

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'notes' => 'Consultation',
    ])->assertRedirect();

    $charge = Charge::query()->where('patient_id', $patient->id)->sole();

    $this->actingAs($accountant)->post(route('billing.charges.post', $charge))->assertRedirect();
    $this->actingAs($accountant)->post(route('billing.charges.cancel', $charge), ['reason' => 'x'])->assertRedirect();
});

test('a user with only billing-charges.view cannot record, post, void, or cancel a charge', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    seedRolesAndPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo(['billing.view', 'billing-accounts.view', 'billing-charges.view']);
    $service = serviceFor();
    priceFor($service, $hospital);

    $this->actingAs($user)->get(route('billing.accounts.show', $charge->patient_account_id))->assertOk();
    $this->actingAs($user)->get(route('billing.charges.create'))->assertForbidden();
    $this->actingAs($user)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'service_id' => $service->id, 'notes' => 'x',
    ])->assertForbidden();
    $this->actingAs($user)->post(route('billing.charges.post', $charge))->assertForbidden();
});

test('a super-admin bypasses billing permission checks and hospital scoping', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('billing.index'))->assertOk();
    $this->actingAs($user)->get(route('billing.accounts.show', $charge->patient_account_id))->assertOk();
    $this->actingAs($user)->post(route('billing.charges.post', $charge))->assertRedirect();
});
