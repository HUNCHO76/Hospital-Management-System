<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Services\PaymentReversalService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 58 — payment reversal lifecycle: full reversal of a posted
 * payment, guard against double-reversal, guard against reversing a payment
 * that already has refunds, balance formula impact, and immutability.
 */
test('reversing a posted payment creates a PaymentReversal with the full payment amount', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $reversal = reversalFor($payment, ['reason' => 'Duplicate payment']);

    expect($reversal)->toBeInstanceOf(PaymentReversal::class);
    expect((float) $reversal->amount)->toBe(50000.0);
    expect($reversal->reason)->toBe('Duplicate payment');
    expect($reversal->original_payment_id)->toBe($payment->id);
    expect($reversal->invoice_id)->toBe($invoice->id);
    expect($reversal->hospital_id)->toBe($hospital->id);
    expect($reversal->patient_id)->toBe($patient->id);
    expect($reversal->reversal_number)->toStartWith('REV-');
    expect($reversal->reversed_at)->not->toBeNull();
});

test('reversing a payment adds its full amount back to the patient outstanding', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_outstanding'])->toBe(0.0);
    expect($outstanding['status'])->toBe('paid');

    reversalFor($payment);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_paid'])->toBe(50000.0);
    expect($outstanding['reversals_total'])->toBe(50000.0);
    expect($outstanding['patient_outstanding'])->toBe(50000.0);
    expect($outstanding['status'])->toBe('unpaid');
});

test('cannot reverse a payment that is not posted', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    // Mark failed first
    $actor = User::factory()->create();
    app(PaymentService::class)->markFailed($payment, 'Cheque bounced', requestAs($actor));
    $payment->refresh();

    expect(fn () => reversalFor($payment))->toThrow(ValidationException::class);
});

test('cannot reverse a payment that already has a reversal', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);
    reversalFor($payment);
    $payment->refresh();

    expect(fn () => reversalFor($payment))->toThrow(ValidationException::class);
});

test('cannot reverse a payment that already has refunds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);
    refundFor($payment, ['amount' => 10000]);

    expect(fn () => reversalFor($payment))->toThrow(ValidationException::class);
});

test('reversal reason is required', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    expect(fn () => reversalFor($payment, ['reason' => '']))->toThrow(ValidationException::class);
    expect(fn () => reversalFor($payment, ['reason' => '   ']))->toThrow(ValidationException::class);
});

test('reversal number is unique per reversal', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    $payment1 = paymentFor($invoice1, ['amount' => 20000]);
    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    $payment2 = paymentFor($invoice2, ['amount' => 20000]);

    $reversal1 = reversalFor($payment1);
    $reversal2 = reversalFor($payment2);

    expect($reversal1->reversal_number)->not->toBe($reversal2->reversal_number);
});

test('reversal is stored with reversed_by pointing to the acting user', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    $payment = paymentFor($invoice, ['amount' => 20000]);

    $actor = User::factory()->create();
    $reversal = app(PaymentReversalService::class)->create($payment, [
        'reason' => 'Accidental duplicate',
        'notes' => null,
    ], requestAs($actor));

    expect($reversal->reversed_by)->toBe($actor->id);
});

test('calculateOutstanding returns new reversal fields alongside backward-compatible existing fields', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 80000]);
    $payment = paymentFor($invoice, ['amount' => 80000]);
    reversalFor($payment);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());

    expect(array_key_exists('reversals_total', $outstanding))->toBeTrue();
    expect(array_key_exists('refunds_total', $outstanding))->toBeTrue();
    expect(array_key_exists('credit_adjustments', $outstanding))->toBeTrue();
    expect(array_key_exists('debit_adjustments', $outstanding))->toBeTrue();
    expect($outstanding['reversals_total'])->toBe(80000.0);
    expect($outstanding['refunds_total'])->toBe(0.0);
});
