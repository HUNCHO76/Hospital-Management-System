<?php

use App\Models\BillingAdjustment;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\BillingAdjustmentService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 58 — billing adjustment lifecycle: credit and debit
 * adjustments against posted invoices, balance formula impact, type/direction
 * validation, immutability guard (DRAFT invoices rejected), and audit trail.
 */
test('a credit adjustment reduces the patient outstanding balance', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    $adjustment = adjustmentFor($invoice, [
        'type' => BillingAdjustment::TYPE_APPROVED_DISCOUNT,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => 20000,
        'reason' => 'Staff discount',
    ]);

    expect($adjustment)->toBeInstanceOf(BillingAdjustment::class);
    expect((float) $adjustment->amount)->toBe(20000.0);
    expect($adjustment->direction)->toBe('credit');
    expect($adjustment->adjustment_number)->toStartWith('ADJ-');

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['credit_adjustments'])->toBe(20000.0);
    expect($outstanding['patient_outstanding'])->toBe(80000.0);
});

test('a debit adjustment increases the patient outstanding balance', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    adjustmentFor($invoice, [
        'type' => BillingAdjustment::TYPE_DEBIT_ADJUSTMENT,
        'direction' => BillingAdjustment::DIRECTION_DEBIT,
        'amount' => 5000,
        'reason' => 'Additional service fee',
    ]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['debit_adjustments'])->toBe(5000.0);
    expect($outstanding['patient_outstanding'])->toBe(105000.0);
});

test('multiple adjustments stack correctly in the balance formula', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    adjustmentFor($invoice, ['direction' => BillingAdjustment::DIRECTION_CREDIT, 'amount' => 10000]);
    adjustmentFor($invoice, ['direction' => BillingAdjustment::DIRECTION_CREDIT, 'amount' => 5000]);
    adjustmentFor($invoice, ['direction' => BillingAdjustment::DIRECTION_DEBIT, 'amount' => 2000, 'type' => BillingAdjustment::TYPE_DEBIT_ADJUSTMENT]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['credit_adjustments'])->toBe(15000.0);
    expect($outstanding['debit_adjustments'])->toBe(2000.0);
    expect($outstanding['patient_outstanding'])->toBe(87000.0);
});

test('cannot post an adjustment against a draft invoice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $actor = User::factory()->create();
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($actor));

    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);

    expect(fn () => adjustmentFor($invoice))->toThrow(ValidationException::class);
});

test('adjustment type must be one of the defined types', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient);

    expect(fn () => adjustmentFor($invoice, ['type' => 'invalid_type']))->toThrow(ValidationException::class);
});

test('adjustment direction must be credit or debit', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient);

    expect(fn () => adjustmentFor($invoice, ['direction' => 'sideways']))->toThrow(ValidationException::class);
});

test('adjustment amount must be positive', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient);

    expect(fn () => adjustmentFor($invoice, ['amount' => 0]))->toThrow(ValidationException::class);
    expect(fn () => adjustmentFor($invoice, ['amount' => -500]))->toThrow(ValidationException::class);
});

test('adjustment reason is required', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient);

    expect(fn () => adjustmentFor($invoice, ['reason' => '']))->toThrow(ValidationException::class);
});

test('adjustment number is unique per adjustment', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);

    $adj1 = adjustmentFor($invoice);
    $adj2 = adjustmentFor($invoice);

    expect($adj1->adjustment_number)->not->toBe($adj2->adjustment_number);
});

test('adjustment is stored with adjusted_by pointing to the acting user', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);

    $actor = User::factory()->create();
    $adj = app(BillingAdjustmentService::class)->create($invoice, [
        'type' => BillingAdjustment::TYPE_WRITE_OFF,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => 5000,
        'reason' => 'Hardship write-off',
        'notes' => null,
    ], requestAs($actor));

    expect($adj->adjusted_by)->toBe($actor->id);
});

test('all balance formula fields are present in calculateOutstanding output after an adjustment', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    adjustmentFor($invoice);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());

    expect(array_key_exists('reversals_total', $outstanding))->toBeTrue();
    expect(array_key_exists('refunds_total', $outstanding))->toBeTrue();
    expect(array_key_exists('credit_adjustments', $outstanding))->toBeTrue();
    expect(array_key_exists('debit_adjustments', $outstanding))->toBeTrue();
});
