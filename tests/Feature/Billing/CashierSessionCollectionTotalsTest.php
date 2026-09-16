<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashierSessionService;
use App\Services\PaymentRefundService;
use App\Services\PaymentService;

/**
 * Phase 7, Step 59 — cashier session collection totals: verify that
 * getCollectionSummary() accurately aggregates real payment/refund/reversal
 * records by method and cashier, and that expected_closing_balance is
 * computed from these real figures at close time.
 */
test('collection summary correctly separates payments by method', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();
    $service = app(CashierSessionService::class);

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 0]);

    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 30000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 30000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 20000,
        'payment_method' => Payment::METHOD_CARD,
        'reference_number' => 'CARD-001',
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $summary = $service->getCollectionSummary($session->fresh());

    expect($summary['paymentTotals'][Payment::METHOD_CASH])->toBe(30000.0);
    expect($summary['paymentTotals'][Payment::METHOD_CARD])->toBe(20000.0);
    expect($summary['paymentTotals'][Payment::METHOD_BANK_TRANSFER])->toBe(0.0);
    // Net cash = cash payments only (card doesn't go through the physical drawer).
    expect($summary['netCashCollected'])->toBe(30000.0);
});

test('collection summary subtracts cash refunds from net cash collected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 0]);

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 50000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    // Cashier issues a cash refund of 10000.
    app(PaymentRefundService::class)->create($payment, [
        'amount' => 10000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Change error',
        'notes' => null,
    ], requestAs($cashier));

    $summary = app(CashierSessionService::class)->getCollectionSummary($session->fresh());

    expect($summary['paymentTotals'][Payment::METHOD_CASH])->toBe(50000.0);
    expect($summary['refundTotals'][Payment::METHOD_CASH])->toBe(10000.0);
    expect($summary['netCashCollected'])->toBe(40000.0);
});

test('collection summary only counts payments made by this cashier', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();
    $otherCashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 0]);

    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 30000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 30000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    // A different cashier at the same hospital also records a payment.
    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 10000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($otherCashier));

    $summary = app(CashierSessionService::class)->getCollectionSummary($session->fresh());

    // Only $cashier's payment (30000) should appear — not $otherCashier's.
    expect($summary['paymentTotals'][Payment::METHOD_CASH])->toBe(30000.0);
    expect($summary['netCashCollected'])->toBe(30000.0);
});

test('expected_closing_balance at close time is opening_balance plus net_cash_collected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 8000]);

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 40000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 40000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    // expected = 8000 + 40000 = 48000
    $closed = app(CashierSessionService::class)->close($session->fresh(), [
        'actual_closing_balance' => 48000,
        'variance_reason' => null,
    ], requestAs($cashier));

    expect((float) $closed->expected_closing_balance)->toBe(48000.0);
    expect((float) $closed->actual_closing_balance)->toBe(48000.0);
    expect((float) $closed->variance)->toBe(0.0);
});
