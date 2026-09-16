<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;

/**
 * Phase 7, Step 57, spec §57.12/§57.23 — a client can never dictate
 * status/hospital_id/patient_id/received_by/posted_at; every identity and
 * computed field comes from the server, derived from the invoice itself
 * (never trusted from the request), mirroring InvoiceSecurityTest/
 * ChargeCaptureTest's own mass-assignment-proof style.
 */
test('a client-supplied status, hospital_id, patient_id, or received_by is never mass-assignable through the store-payment request', function () {
    $hospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $otherUser = User::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 5000,
        'payment_method' => 'cash',
        'status' => 'failed',
        'hospital_id' => $otherHospital->id,
        'patient_id' => 999999,
        'received_by' => $otherUser->id,
        'posted_at' => now()->subYear()->toIso8601String(),
    ])->assertRedirect();

    $payment = $invoice->fresh()->payments()->sole();

    expect($payment->status)->toBe(Payment::STATUS_POSTED);
    expect($payment->hospital_id)->toBe($hospital->id);
    expect($payment->patient_id)->toBe($patient->id);
    expect($payment->received_by)->toBe($accountant->id);
    expect($payment->posted_at->isToday())->toBeTrue();
});

test('a client cannot force a specific payment_number or receipt_number', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 5000,
        'payment_method' => 'cash',
        'payment_number' => 'PAY-2000-000001',
        'receipt_number' => 'RCT-2000-000001',
    ])->assertRedirect();

    $payment = $invoice->fresh()->payments()->sole();
    expect($payment->payment_number)->not->toBe('PAY-2000-000001');
    expect($payment->receipt_number)->not->toBe('RCT-2000-000001');
});

test('an amount exceeding the outstanding balance is rejected over HTTP, never trusting the client\'s own number', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 999999,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors('amount');

    expect($invoice->fresh()->payments()->count())->toBe(0);
});

test('a negative or zero amount is rejected over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 0,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors('amount');

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => -5000,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors('amount');
});

test('a client cannot record a payment against an invoice belonging to another patient by submitting its id directly', function () {
    $hospital = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospital, $patientA, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    // A payment is always attributed to the invoice's own patient/account —
    // there is no patient_id field on the request at all for a client to
    // even attempt to override.
    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoiceA->id,
        'amount' => 5000,
        'payment_method' => 'cash',
    ])->assertRedirect();

    $payment = $invoiceA->fresh()->payments()->sole();
    expect($payment->patient_id)->toBe($patientA->id);
    expect($payment->patient_id)->not->toBe($patientB->id);
});

test('a failure reason containing markup is stored as plain text, never interpreted as HTML server-side', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);
    $accountant = userWithRole('accountant');

    $payload = '<script>alert(1)</script>';

    $this->actingAs($accountant)->post(route('billing.payments.void', $payment), ['reason' => $payload])->assertRedirect();

    expect($payment->fresh()->failure_reason)->toBe($payload);
});

test('notes containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $accountant = userWithRole('accountant');

    $payload = '<img src=x onerror=alert(1)>';

    $this->actingAs($accountant)->post(route('billing.payments.store'), [
        'invoice_id' => $invoice->id,
        'amount' => 5000,
        'payment_method' => 'cash',
        'notes' => $payload,
    ])->assertRedirect();

    $payment = $invoice->fresh()->payments()->sole();
    expect($payment->notes)->toBe($payload);
});
