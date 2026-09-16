<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Services\InsuranceBillingService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 57 — the payment lifecycle: outstanding calculation
 * (self-pay and insurance-adjusted), partial/full payment, overpayment
 * rejection, payment/receipt number uniqueness, method/reference
 * validation, and the one controlled correction (markFailed()).
 */
test('a self-pay invoice (no insurance ever calculated) has patient responsibility equal to its full total', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);

    expect($outstanding['invoice_total'])->toBe(10000.0);
    expect($outstanding['insurance_responsibility'])->toBe(0.0);
    expect($outstanding['patient_responsibility'])->toBe(10000.0);
    expect($outstanding['patient_paid'])->toBe(0.0);
    expect($outstanding['patient_outstanding'])->toBe(10000.0);
    expect($outstanding['status'])->toBe('unpaid');
});

test('an insurance-calculated invoice has patient responsibility reduced to the insurer\'s remainder, never overwriting the insurance allocation', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 70]);
    primaryCoverageFor($patient, $scheme);

    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 100000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));
    app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($accountant));
    $invoice = app(InvoiceService::class)->post($invoice->fresh(), requestAs($accountant));

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);

    expect($outstanding['invoice_total'])->toBe(100000.0);
    expect($outstanding['insurance_responsibility'])->toBe(70000.0);
    expect($outstanding['patient_responsibility'])->toBe(30000.0);
    expect($outstanding['patient_outstanding'])->toBe(30000.0);

    // The insurance allocation itself is never touched by any payment calculation.
    $allocation = $charge->fresh()->insuranceAllocation;
    expect((float) $allocation->covered_amount)->toBe(70000.0);
});

test('a partial payment reduces the outstanding balance without marking the invoice paid', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    paymentFor($invoice, ['amount' => 40000]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);
    expect($outstanding['patient_paid'])->toBe(40000.0);
    expect($outstanding['patient_outstanding'])->toBe(60000.0);
    expect($outstanding['status'])->toBe('partially_paid');
});

test('three partial payments summing to the full responsibility mark the invoice paid', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    paymentFor($invoice, ['amount' => 40000]);
    paymentFor($invoice, ['amount' => 30000]);
    paymentFor($invoice, ['amount' => 30000]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);
    expect($outstanding['patient_paid'])->toBe(100000.0);
    expect($outstanding['patient_outstanding'])->toBe(0.0);
    expect($outstanding['status'])->toBe('paid');
});

test('a single full payment marks the invoice paid immediately', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    paymentFor($invoice, ['amount' => 50000]);

    expect(app(PaymentService::class)->calculateOutstanding($invoice)['status'])->toBe('paid');
});

test('overpayment beyond the outstanding balance is rejected outright', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    expect(fn () => paymentFor($invoice, ['amount' => 70000]))->toThrow(ValidationException::class);

    expect(app(PaymentService::class)->calculateOutstanding($invoice)['patient_outstanding'])->toBe(50000.0);
});

test('a payment against an invoice with zero outstanding balance is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    paymentFor($invoice, ['amount' => 50000]);

    expect(fn () => paymentFor($invoice, ['amount' => 1]))->toThrow(ValidationException::class);
});

test('a payment against a non-posted invoice is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $draftInvoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs(userWithRole('accountant')));

    expect(fn () => paymentFor($draftInvoice, ['amount' => 5000]))->toThrow(ValidationException::class);
});

test('non-cash methods require a reference number', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    expect(fn () => paymentFor($invoice, ['payment_method' => Payment::METHOD_MOBILE_MONEY, 'reference_number' => null]))
        ->toThrow(ValidationException::class);
});

test('cash payments never require a reference number', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    $payment = paymentFor($invoice, ['payment_method' => Payment::METHOD_CASH, 'reference_number' => null]);
    expect($payment->reference_number)->toBeNull();
});

test('an invalid payment method is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    expect(fn () => paymentFor($invoice, ['payment_method' => 'bitcoin']))->toThrow(ValidationException::class);
});

test('a future payment date is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    expect(fn () => paymentFor($invoice, ['payment_date' => now()->addDay()->toDateString()]))->toThrow(ValidationException::class);
});

test('duplicate reference numbers for the same hospital and method are rejected', function () {
    $hospital = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospital, $patientA, ['unit_price' => 50000]);
    $invoiceB = postedInvoiceFor($hospital, $patientB, ['unit_price' => 50000]);

    paymentFor($invoiceA, ['amount' => 10000, 'payment_method' => Payment::METHOD_BANK_TRANSFER, 'reference_number' => 'REF-001']);

    expect(fn () => paymentFor($invoiceB, ['amount' => 10000, 'payment_method' => Payment::METHOD_BANK_TRANSFER, 'reference_number' => 'REF-001']))
        ->toThrow(ValidationException::class);
});

test('the same reference number is allowed again at a different hospital', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospitalA, $patientA, ['unit_price' => 50000]);
    $invoiceB = postedInvoiceFor($hospitalB, $patientB, ['unit_price' => 50000]);

    paymentFor($invoiceA, ['amount' => 10000, 'payment_method' => Payment::METHOD_BANK_TRANSFER, 'reference_number' => 'REF-SHARED']);

    $paymentB = paymentFor($invoiceB, ['amount' => 10000, 'payment_method' => Payment::METHOD_BANK_TRANSFER, 'reference_number' => 'REF-SHARED']);
    expect($paymentB->reference_number)->toBe('REF-SHARED');
});

test('payment numbers and receipt numbers are both generated and unique', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    $payment = paymentFor($invoice, ['amount' => 10000]);

    expect($payment->payment_number)->toStartWith('PAY-'.now()->year.'-');
    expect($payment->receipt_number)->toStartWith('RCT-'.now()->year.'-');
    expect($payment->payment_number)->not->toBe($payment->receipt_number);
});

test('a payment always creates exactly one allocation matching its own amount', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    $payment = paymentFor($invoice, ['amount' => 20000]);

    $allocation = $payment->allocations()->sole();
    expect((float) $allocation->amount)->toBe(20000.0);
    expect($allocation->invoice_id)->toBe($invoice->id);
});

test('marking a posted payment failed removes it from the paid total without deleting the record', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 20000]);
    $actor = userWithRole('accountant');

    app(PaymentService::class)->markFailed($payment, 'bank transfer bounced', requestAs($actor));

    expect(Payment::query()->whereKey($payment->id)->exists())->toBeTrue();
    expect($payment->fresh()->status)->toBe(Payment::STATUS_FAILED);
    expect(app(PaymentService::class)->calculateOutstanding($invoice)['patient_paid'])->toBe(0.0);
});

test('the same payment can never be marked failed twice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 20000]);
    $actor = userWithRole('accountant');

    app(PaymentService::class)->markFailed($payment, 'first reason', requestAs($actor));

    expect(fn () => app(PaymentService::class)->markFailed($payment->fresh(), 'second reason', requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('marking a payment failed without a reason is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 20000]);

    expect(fn () => app(PaymentService::class)->markFailed($payment, '', requestAs(userWithRole('accountant'))))
        ->toThrow(ValidationException::class);
});

test('outstandingBalanceForAccount sums outstanding across every posted invoice on the account', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoiceA = postedInvoiceFor($hospital, $patient, ['unit_price' => 30000]);
    $invoiceB = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    paymentFor($invoiceA, ['amount' => 10000]);

    $total = app(PaymentService::class)->outstandingBalanceForAccount($invoiceA->fresh()->patientAccount);
    expect($total)->toBe(40000.0);
});
