<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;

/**
 * Authorization and facility isolation for the payment surface (Phase 7,
 * Step 57) — 'billing-payments.{view,create,void}', hospital-scoped
 * exactly like every other billing ability (PaymentPolicy::
 * belongsToHospital() precedent).
 */
test('a guest is redirected to login for every payment route', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);

    $this->get(route('billing.payments.index'))->assertRedirect(route('login'));
    $this->get(route('billing.payments.create'))->assertRedirect(route('login'));
    $this->post(route('billing.payments.store'))->assertRedirect(route('login'));
    $this->get(route('billing.payments.show', $payment))->assertRedirect(route('login'));
    $this->get(route('billing.payments.receipt', $payment))->assertRedirect(route('login'));
    $this->post(route('billing.payments.void', $payment))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or record payments', function () {
    seedRolesAndPermissions();
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing.payments.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.payments.create'))->assertForbidden();
    $this->actingAs($user)->get(route('billing.payments.show', $payment))->assertForbidden();
    $this->actingAs($user)->post(route('billing.payments.void', $payment), ['reason' => 'x'])->assertForbidden();
});

test('a doctor holds no billing-payments permission', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('billing.payments.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.payments.create'))->assertForbidden();
});

test('an accountant can record, view, and mark a payment failed end to end over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing.payments.create'))->assertOk();

    $response = $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 10000,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    $payment = $invoice->fresh()->payments()->sole();
    $response->assertRedirect(route('billing.payments.show', $payment));

    $this->actingAs($accountant)->get(route('billing.payments.show', $payment))->assertOk();
    $this->actingAs($accountant)->get(route('billing.payments.receipt', $payment))->assertOk();
    $this->actingAs($accountant)->post(route('billing.payments.void', $payment), ['reason' => 'test correction'])->assertRedirect();

    expect($payment->fresh()->status)->toBe('failed');
});

test('a user with only billing-payments.view cannot record or void a payment', function () {
    seedRolesAndPermissions();
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);

    $viewOnly = User::factory()->create();
    $viewOnly->givePermissionTo('billing-payments.view');

    $this->actingAs($viewOnly)->get(route('billing.payments.show', $payment))->assertOk();
    $this->actingAs($viewOnly)->post(route('billing.payments.store'), ['invoice_id' => $invoice->id, 'amount' => 1000, 'payment_method' => 'cash'])
        ->assertForbidden();
    $this->actingAs($viewOnly)->post(route('billing.payments.void', $payment), ['reason' => 'x'])->assertForbidden();
});

test('a super-admin bypasses billing-payments permission checks', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('billing.payments.index'))->assertOk();
    $this->actingAs($user)->get(route('billing.payments.create'))->assertOk();
});

test('a hospital-admin cannot view, record into, or void another hospital\'s payment by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospitalA, $patientA, ['unit_price' => 10000]);
    $invoiceB = postedInvoiceFor($hospitalB, $patientB, ['unit_price' => 10000]);
    $paymentA = paymentFor($invoiceA, ['amount' => 5000]);
    $paymentB = paymentFor($invoiceB, ['amount' => 5000]);

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.payments.show', $paymentA))->assertOk();
    $this->actingAs($adminA)->get(route('billing.payments.show', $paymentB))->assertForbidden();
    $this->actingAs($adminA)->post(route('billing.payments.void', $paymentB), ['reason' => 'x'])->assertForbidden();

    $this->actingAs($adminA)->post(route('billing.payments.store'), [
        'invoice_id' => $invoiceB->id,
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertForbidden();

    expect($paymentB->fresh()->status)->toBe('posted');
});

test('an accountant (not hospital-admin) is unrestricted across hospitals for payments', function () {
    $hospitalA = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospitalA, $patientA, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoiceA->id,
        'amount' => 5000,
        'payment_method' => 'cash',
    ])->assertRedirect();
});
