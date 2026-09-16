<?php

use App\Models\ChargeInsuranceAllocation;
use App\Services\ChargeService;
use App\Services\InsuranceBillingService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 57, spec §57.33 — the complete, realistic pathway, using
 * the spec's own exact worked numbers: service 100,000, insurance 70,000,
 * patient responsibility 30,000, first payment 10,000 (outstanding 20,000),
 * second payment 20,000 (outstanding 0). Verifies every downstream figure
 * (receipt, invoice balance, account balance, allocation history) agrees
 * with the same underlying transaction, with no amount ever duplicated.
 */
test('the full pathway from charge through insurance coverage to two payments leaves every balance correct and consistent', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 70]);
    primaryCoverageFor($ctx['patient'], $scheme);

    $charge = postedChargeFor($ctx['hospital'], $ctx['patient'], ['unit_price' => 100000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));
    app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($accountant));
    $invoice = app(InvoiceService::class)->post($invoice->fresh(), requestAs($accountant));

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);
    expect($outstanding['invoice_total'])->toBe(100000.0);
    expect($outstanding['insurance_responsibility'])->toBe(70000.0);
    expect($outstanding['patient_responsibility'])->toBe(30000.0);
    expect($outstanding['patient_outstanding'])->toBe(30000.0);

    // First payment: 10,000.
    $payment1 = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 10000,
        'payment_method' => 'cash',
    ], requestAs($accountant));

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_paid'])->toBe(10000.0);
    expect($outstanding['patient_outstanding'])->toBe(20000.0);
    expect($outstanding['status'])->toBe('partially_paid');

    // Second payment: 20,000 — clears the balance.
    $payment2 = app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 20000,
        'payment_method' => 'cash',
    ], requestAs($accountant));

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_paid'])->toBe(30000.0);
    expect($outstanding['patient_outstanding'])->toBe(0.0);
    expect($outstanding['status'])->toBe('paid');

    // No amount is duplicated: exactly two payments, two allocations, summing exactly to patient responsibility.
    expect($invoice->fresh()->payments()->count())->toBe(2);
    expect((float) $invoice->fresh()->paymentAllocations()->sum('amount'))->toBe(30000.0);

    // The insurance allocation itself was never touched by either payment.
    $allocation = $charge->fresh()->insuranceAllocation;
    expect($allocation->status)->toBe(ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED);
    expect((float) $allocation->covered_amount)->toBe(70000.0);
    expect((float) $allocation->patient_responsibility)->toBe(30000.0);

    // Each payment produced its own distinct, unique receipt.
    expect($payment1->receipt_number)->not->toBe($payment2->receipt_number);
    expect($payment1->payment_number)->not->toBe($payment2->payment_number);

    // The account-wide outstanding balance across the whole account agrees.
    $account = $invoice->fresh()->patientAccount;
    expect(app(PaymentService::class)->outstandingBalanceForAccount($account))->toBe(0.0);

    // Financial immutability: the original charge/invoice amounts were never rewritten.
    expect((float) $charge->fresh()->net_amount)->toBe(100000.0);
    expect((float) $invoice->fresh()->total_amount)->toBe(100000.0);

    // Every step left its own audit trail.
    $this->assertDatabaseHas('audit_logs', ['action' => 'payment_posted', 'auditable_id' => $payment1->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'payment_posted', 'auditable_id' => $payment2->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receipt_generated', 'auditable_id' => $payment1->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receipt_generated', 'auditable_id' => $payment2->id]);
});

test('a fully insurance-covered charge (patient responsibility zero) needs no payment at all', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_type' => 'full', 'coverage_percentage' => null]);
    primaryCoverageFor($ctx['patient'], $scheme);

    $charge = postedChargeFor($ctx['hospital'], $ctx['patient'], ['unit_price' => 50000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));
    app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($accountant));
    $invoice = app(InvoiceService::class)->post($invoice->fresh(), requestAs($accountant));

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice);
    expect($outstanding['patient_responsibility'])->toBe(0.0);
    expect($outstanding['patient_outstanding'])->toBe(0.0);
    expect($outstanding['status'])->toBe('paid');

    expect(fn () => app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 1,
        'payment_method' => 'cash',
    ], requestAs($accountant)))->toThrow(ValidationException::class);
});

test('a voided charge never reappears as patient responsibility', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    $chargeA = postedChargeFor($ctx['hospital'], $ctx['patient'], ['unit_price' => 30000]);
    $chargeB = postedChargeFor($ctx['hospital'], $ctx['patient'], ['unit_price' => 20000]);
    $invoice = app(InvoiceService::class)->createDraft($chargeA->patientAccount, [$chargeA->id, $chargeB->id], null, requestAs($accountant));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($accountant));

    app(ChargeService::class)->cancel($chargeB->fresh(), 'billing correction', requestAs($accountant));

    // A charge cancelled after the invoice was already posted still counts
    // toward it here (Invoice's own total is frozen at post time) — this
    // assertion simply proves calculateOutstanding() reads live charge
    // status, matching the spec's own "never derive financial truth from a
    // stored field" principle used throughout this codebase; the invoice's
    // own frozen total_amount is a separate, deliberately immutable figure.
    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_responsibility'])->toBe(30000.0);
});
