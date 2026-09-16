<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\PaymentRefundService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 58 — payment refund lifecycle: partial and full refunds,
 * refundable-amount validation, duplicate-reference protection, guard against
 * refunding a reversed payment, balance formula impact, and immutability.
 */
test('a partial refund creates a PaymentRefund row and reduces the patient outstanding', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);
    $payment = paymentFor($invoice, ['amount' => 100000]);

    $refund = refundFor($payment, ['amount' => 30000]);

    expect($refund)->toBeInstanceOf(PaymentRefund::class);
    expect((float) $refund->amount)->toBe(30000.0);
    expect($refund->payment_id)->toBe($payment->id);
    expect($refund->invoice_id)->toBe($invoice->id);
    expect($refund->hospital_id)->toBe($hospital->id);
    expect($refund->patient_id)->toBe($patient->id);
    expect($refund->refund_number)->toStartWith('REF-');
    expect($refund->processed_at)->not->toBeNull();

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['refunds_total'])->toBe(30000.0);
    expect($outstanding['patient_outstanding'])->toBe(30000.0);
});

test('a full refund (equal to payment amount) brings outstanding back to its pre-payment value', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 60000]);
    $payment = paymentFor($invoice, ['amount' => 60000]);

    refundFor($payment, ['amount' => 60000]);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['patient_paid'])->toBe(60000.0);
    expect($outstanding['refunds_total'])->toBe(60000.0);
    expect($outstanding['patient_outstanding'])->toBe(60000.0);
});

test('multiple partial refunds summing to the payment amount are accepted', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 60000]);
    $payment = paymentFor($invoice, ['amount' => 60000]);

    refundFor($payment, ['amount' => 20000, 'refund_reference' => 'REF-A']);
    refundFor($payment, ['amount' => 20000, 'refund_reference' => 'REF-B']);
    refundFor($payment, ['amount' => 20000, 'refund_reference' => 'REF-C']);

    $outstanding = app(PaymentService::class)->calculateOutstanding($invoice->fresh());
    expect($outstanding['refunds_total'])->toBe(60000.0);
});

test('refund amount exceeding refundable balance is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);
    refundFor($payment, ['amount' => 30000, 'refund_reference' => 'REF-X']);

    // Only 20000 remains refundable
    expect(fn () => refundFor($payment, ['amount' => 25000, 'refund_reference' => 'REF-Y']))->toThrow(ValidationException::class);
});

test('cannot refund a payment that has been reversed', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);
    reversalFor($payment);

    expect(fn () => refundFor($payment->fresh()))->toThrow(ValidationException::class);
});

test('cannot refund a failed payment', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $actor = User::factory()->create();
    app(PaymentService::class)->markFailed($payment, 'Test', requestAs($actor));

    expect(fn () => refundFor($payment->fresh()))->toThrow(ValidationException::class);
});

test('refund method is required', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    expect(fn () => refundFor($payment, ['amount' => 10000, 'refund_method' => '']))->toThrow(ValidationException::class);
});

test('refund amount must be positive', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    expect(fn () => refundFor($payment, ['amount' => 0]))->toThrow(ValidationException::class);
    expect(fn () => refundFor($payment, ['amount' => -1000]))->toThrow(ValidationException::class);
});

test('duplicate refund reference within the same hospital is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment1 = paymentFor($invoice1, ['amount' => 50000]);
    refundFor($payment1, ['amount' => 10000, 'refund_method' => Payment::METHOD_CARD, 'refund_reference' => 'CARD-TXN-999']);

    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment2 = paymentFor($invoice2, ['amount' => 50000]);

    expect(fn () => refundFor($payment2, ['amount' => 10000, 'refund_method' => Payment::METHOD_CARD, 'refund_reference' => 'CARD-TXN-999']))
        ->toThrow(ValidationException::class);
});

test('refund is stored with processed_by pointing to the acting user', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $actor = User::factory()->create();
    $refund = app(PaymentRefundService::class)->create($payment, [
        'amount' => 10000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Overpayment correction',
        'notes' => null,
    ], requestAs($actor));

    expect($refund->processed_by)->toBe($actor->id);
});
