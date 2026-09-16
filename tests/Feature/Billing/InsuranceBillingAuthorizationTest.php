<?php

use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\InvoiceService;

/**
 * Authorization and facility isolation for the invoice insurance surface
 * (Phase 7, Step 56) — 'billing-insurance.view'/'.calculate', hospital-
 * scoped exactly like every other invoice ability (InvoicePolicy's own
 * belongsToHospital() precedent).
 */
function draftInvoiceWithChargeFor(Hospital $hospital, Patient $patient, User $actor): Invoice
{
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);

    return app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($actor));
}

test('a guest is redirected to login for both insurance routes', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, User::factory()->create());

    $this->get(route('billing.invoices.insurance.show', $invoice))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.insurance.calculate', $invoice))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or calculate insurance for an invoice', function () {
    seedRolesAndPermissions();
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, User::factory()->create());
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing.invoices.insurance.show', $invoice))->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.insurance.calculate', $invoice))->assertForbidden();
});

test('a doctor holds no billing-insurance permission', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, User::factory()->create());
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('billing.invoices.insurance.show', $invoice))->assertForbidden();
});

test('an accountant can view and calculate insurance for an invoice end to end over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $accountant = userWithRole('accountant');
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, $accountant);

    $this->actingAs($accountant)->get(route('billing.invoices.insurance.show', $invoice))->assertOk();
    $this->actingAs($accountant)->post(route('billing.invoices.insurance.calculate', $invoice))->assertRedirect();

    expect(ChargeInsuranceAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

test('a user with only billing-insurance.view cannot calculate coverage', function () {
    seedRolesAndPermissions();
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $viewOnly = User::factory()->create();
    $viewOnly->givePermissionTo('billing-insurance.view');
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, User::factory()->create());

    $this->actingAs($viewOnly)->get(route('billing.invoices.insurance.show', $invoice))->assertOk();
    $this->actingAs($viewOnly)->post(route('billing.invoices.insurance.calculate', $invoice))->assertForbidden();
});

test('a super-admin bypasses billing-insurance permission checks', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = draftInvoiceWithChargeFor($hospital, $patient, User::factory()->create());
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('billing.invoices.insurance.show', $invoice))->assertOk();
});

test('a hospital-admin cannot view or calculate insurance for another hospital\'s invoice by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();

    $actor = User::factory()->create();
    $invoiceA = draftInvoiceWithChargeFor($hospitalA, $patientA, $actor);
    $invoiceB = draftInvoiceWithChargeFor($hospitalB, $patientB, $actor);

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.invoices.insurance.show', $invoiceB))->assertForbidden();
    $this->actingAs($adminA)->post(route('billing.invoices.insurance.calculate', $invoiceB))->assertForbidden();
    $this->actingAs($adminA)->get(route('billing.invoices.insurance.show', $invoiceA))->assertOk();
});

test('an accountant (not hospital-admin) is unrestricted across hospitals for insurance calculation', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patientA = Patient::factory()->create();

    $accountant = userWithRole('accountant');
    $invoiceA = draftInvoiceWithChargeFor($hospitalA, $patientA, $accountant);

    $this->actingAs($accountant)->get(route('billing.invoices.insurance.show', $invoiceA))->assertOk();
});
