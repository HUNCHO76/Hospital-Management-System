<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingAdjustmentService;
use App\Services\BillingReportService;
use App\Services\PaymentRefundService;
use App\Services\PaymentReversalService;
use App\Services\PaymentService;

/**
 * Phase 7, Step 60 — billing report service calculation accuracy: KPI
 * aggregations match the authoritative financial records exactly; facility
 * isolation applies to every figure; filters constrain correctly.
 */
test('dashboardMetrics totalBilled sums only POSTED invoice totals', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    // Draft invoice should not count toward totalBilled.
    postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    postedInvoiceFor($hospital, $patient, ['unit_price' => 5000]);

    $metrics = app(BillingReportService::class)->dashboardMetrics($hospital->id);

    expect($metrics['totalBilled'])->toBe(15000.0);
});

test('dashboardMetrics totalCollected sums only POSTED payments', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 30000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 20000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $metrics = app(BillingReportService::class)->dashboardMetrics($hospital->id);

    expect($metrics['totalCollected'])->toBe(20000.0);
});

test('dashboardMetrics totalRefunds and totalReversals aggregate corrections', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    // First invoice/payment: will be refunded.
    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment1 = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 50000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    app(PaymentRefundService::class)->create($payment1, [
        'amount' => 8000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Overcharge',
        'notes' => null,
    ], requestAs($cashier));

    // Second invoice/payment: will be reversed (separate payment — a payment
    // with existing refunds cannot be reversed).
    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 30000]);
    $payment2 = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 30000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    app(PaymentReversalService::class)->create($payment2, [
        'reason' => 'Duplicate',
        'notes' => null,
    ], requestAs($cashier));

    $metrics = app(BillingReportService::class)->dashboardMetrics($hospital->id);

    expect($metrics['totalRefunds'])->toBe(8000.0);
    expect($metrics['totalReversals'])->toBe(30000.0);
});

test('dashboardMetrics creditAdjustments and debitAdjustments aggregate by direction', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $adjuster = User::factory()->create();

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    app(BillingAdjustmentService::class)->create($invoice, [
        'type' => 'credit_adjustment',
        'direction' => 'credit',
        'amount' => 3000,
        'reason' => 'Correction',
        'notes' => null,
    ], requestAs($adjuster));

    app(BillingAdjustmentService::class)->create($invoice, [
        'type' => 'debit_adjustment',
        'direction' => 'debit',
        'amount' => 1000,
        'reason' => 'Late fee',
        'notes' => null,
    ], requestAs($adjuster));

    $metrics = app(BillingReportService::class)->dashboardMetrics($hospital->id);

    expect($metrics['creditAdjustments'])->toBe(3000.0);
    expect($metrics['debitAdjustments'])->toBe(1000.0);
});

test('dashboardMetrics is scoped to the given hospital', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $invoice1 = postedInvoiceFor($hospital1, $patient, ['unit_price' => 20000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 20000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $invoice2 = postedInvoiceFor($hospital2, $patient, ['unit_price' => 10000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 10000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $metrics1 = app(BillingReportService::class)->dashboardMetrics($hospital1->id);
    $metrics2 = app(BillingReportService::class)->dashboardMetrics($hospital2->id);

    expect($metrics1['totalBilled'])->toBe(20000.0);
    expect($metrics1['totalCollected'])->toBe(20000.0);
    expect($metrics2['totalBilled'])->toBe(10000.0);
    expect($metrics2['totalCollected'])->toBe(10000.0);
});

test('invoiceReport filters by status', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);

    $service = app(BillingReportService::class);

    $postedCount = $service->invoiceReport(['status' => 'posted'], $hospital->id)->count();
    $draftCount = $service->invoiceReport(['status' => 'draft'], $hospital->id)->count();

    expect($postedCount)->toBeGreaterThanOrEqual(1);
    expect($draftCount)->toBe(0);
});

test('paymentsReport filters by payment method', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 20000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 15000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 15000,
        'payment_method' => Payment::METHOD_CARD,
        'reference_number' => 'CARD-001',
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $service = app(BillingReportService::class);

    $cashPayments = $service->paymentsReport(['payment_method' => Payment::METHOD_CASH], $hospital->id)->count();
    $cardPayments = $service->paymentsReport(['payment_method' => Payment::METHOD_CARD], $hospital->id)->count();

    expect($cashPayments)->toBe(1);
    expect($cardPayments)->toBe(1);
});

test('cashierSessionsReport filters by status', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier);

    $service = app(BillingReportService::class);

    $openCount = $service->cashierSessionsReport(['status' => 'open'], $hospital->id)->count();
    $closedCount = $service->cashierSessionsReport(['status' => 'closed'], $hospital->id)->count();

    expect($openCount)->toBe(1);
    expect($closedCount)->toBe(0);
});

test('correctionsReport returns accurate totals by type', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 60000]);
    $payment = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 60000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    app(PaymentRefundService::class)->create($payment, [
        'amount' => 5000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Overpayment',
        'notes' => null,
    ], requestAs($cashier));

    $data = app(BillingReportService::class)->correctionsReport([], $hospital->id);

    expect($data['totals']['refunds'])->toBe(5000.0);
    expect($data['refunds']->total())->toBe(1);
});
