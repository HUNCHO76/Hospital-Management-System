<?php

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;

/**
 * Permission gates for the invoice module (Phase 7, Step 55, spec §33-35):
 * every route requires authentication and the specific billing-invoices.*
 * ability, mirroring BillingAuthorizationTest's own shape exactly.
 */
test('a guest is redirected to login for every invoice route', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));

    $this->get(route('billing.invoices.index'))->assertRedirect(route('login'));
    $this->get(route('billing.invoices.create', ['patient_account_id' => $account->id]))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.store'))->assertRedirect(route('login'));
    $this->get(route('billing.invoices.show', $invoice))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.charges.add', $invoice))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.post', $invoice))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.void', $invoice))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or act on invoices', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing.invoices.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.invoices.create', ['patient_account_id' => $account->id]))->assertForbidden();
    $this->actingAs($user)->get(route('billing.invoices.show', $invoice))->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.post', $invoice))->assertForbidden();
});

test('a doctor holds no invoice permission and cannot view or act on invoices', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('billing.invoices.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.invoices.show', $invoice))->assertForbidden();
});

test('an accountant can create, build, post, and void/cancel an invoice end to end over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $chargeA = postedChargeFor($hospital, $patient);
    $chargeB = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing.invoices.index'))->assertOk();
    $this->actingAs($accountant)->get(route('billing.invoices.create', ['patient_account_id' => $account->id]))->assertOk();

    $this->actingAs($accountant)->post(route('billing.invoices.store'), [
        'patient_account_id' => $account->id,
        'charge_ids' => [$chargeA->id],
    ])->assertRedirect();

    $invoice = Invoice::query()->where('patient_account_id', $account->id)->sole();

    $this->actingAs($accountant)->get(route('billing.invoices.show', $invoice))->assertOk();
    $this->actingAs($accountant)->post(route('billing.invoices.charges.add', $invoice), ['charge_id' => $chargeB->id])->assertRedirect();
    $this->actingAs($accountant)->post(route('billing.invoices.post', $invoice))->assertRedirect();
    $this->actingAs($accountant)->post(route('billing.invoices.cancel', $invoice), ['reason' => 'x'])->assertRedirect();
});

test('a user with only billing-invoices.view cannot create, post, or void an invoice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));
    seedRolesAndPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo(['billing.view', 'billing-accounts.view', 'billing-invoices.view']);

    $this->actingAs($user)->get(route('billing.invoices.show', $invoice))->assertOk();
    $this->actingAs($user)->get(route('billing.invoices.create', ['patient_account_id' => $account->id]))->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.store'), [
        'patient_account_id' => $account->id, 'charge_ids' => [],
    ])->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.post', $invoice))->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.void', $invoice), ['reason' => 'x'])->assertForbidden();
});

test('a user with billing-invoices.create but not .post cannot post an invoice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));
    seedRolesAndPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo(['billing.view', 'billing-accounts.view', 'billing-invoices.view', 'billing-invoices.create']);

    $this->actingAs($user)->post(route('billing.invoices.post', $invoice))->assertForbidden();
});

test('a super-admin bypasses invoice permission checks and hospital scoping', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs(userWithRole('accountant')));
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('billing.invoices.index'))->assertOk();
    $this->actingAs($user)->get(route('billing.invoices.show', $invoice))->assertOk();
    $this->actingAs($user)->post(route('billing.invoices.post', $invoice))->assertRedirect();
});
