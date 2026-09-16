<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency test (Phase 7, Step 57, spec §57.14): repeated
 * back-to-back payment attempts against the same outstanding balance must
 * never drive it negative — "Cashier A pays 50,000, Cashier B also pays
 * 50,000" must never both succeed. `createPayment()` locks the invoice row
 * first and recomputes `calculateOutstanding()` under that lock, so a
 * second (or later) attempt correctly sees the balance the first attempt
 * already committed. Mirrors InvoiceConcurrencyTest/StockConcurrencyTest's
 * own "simulate repeated attempts" approach for a single-threaded test
 * runner.
 */
test('several back-to-back full-amount payment attempts against the same balance — only one succeeds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $actor = userWithRole('accountant');

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            app(PaymentService::class)->createPayment([
                'invoice_id' => $invoice->id,
                'amount' => 50000,
                'payment_method' => Payment::METHOD_CASH,
            ], requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(app(PaymentService::class)->calculateOutstanding($invoice)['patient_outstanding'])->toBe(0.0);
    expect((float) PaymentAllocation::query()->where('invoice_id', $invoice->id)->sum('amount'))->toBe(50000.0);
});

test('several back-to-back partial payment attempts never drive the outstanding balance negative', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);
    $actor = userWithRole('accountant');

    $successes = 0;
    $rejections = 0;

    // Five attempts of 30,000 each against a 100,000 balance: exactly three
    // succeed (90,000), the fourth would exceed the remaining 10,000 and is
    // rejected, matching this codebase's own overpayment-protection
    // precedent (StockConcurrencyTest's identical "successes/rejections"
    // shape).
    for ($i = 0; $i < 5; $i++) {
        try {
            app(PaymentService::class)->createPayment([
                'invoice_id' => $invoice->id,
                'amount' => 30000,
                'payment_method' => Payment::METHOD_CASH,
            ], requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(3);
    expect($rejections)->toBe(2);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);
    expect($outstanding['patient_outstanding'])->toBe(10000.0);
    expect($outstanding['patient_outstanding'])->toBeGreaterThanOrEqual(0.0);
});

test('several payments created back-to-back across different invoices all receive unique payment and receipt numbers', function () {
    $hospital = Hospital::factory()->create();
    $actor = userWithRole('accountant');

    $paymentNumbers = [];
    $receiptNumbers = [];

    for ($i = 0; $i < 5; $i++) {
        $invoice = postedInvoiceFor($hospital, Patient::factory()->create(), ['unit_price' => 10000]);

        $payment = app(PaymentService::class)->createPayment([
            'invoice_id' => $invoice->id,
            'amount' => 5000,
            'payment_method' => Payment::METHOD_CASH,
        ], requestAs($actor));

        $paymentNumbers[] = $payment->payment_number;
        $receiptNumbers[] = $payment->receipt_number;
    }

    expect(count($paymentNumbers))->toBe(count(array_unique($paymentNumbers)));
    expect(count($receiptNumbers))->toBe(count(array_unique($receiptNumbers)));
});

test('only one of several back-to-back attempts to mark the same payment failed succeeds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 20000]);
    $actor = userWithRole('accountant');

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            app(PaymentService::class)->markFailed($payment->fresh(), 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});
