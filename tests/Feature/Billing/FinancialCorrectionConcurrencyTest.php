<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\User;
use App\Services\PaymentRefundService;
use App\Services\PaymentReversalService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 58 — concurrency: double-reversal and over-refund guards
 * are enforced within the same DB::transaction + lockForUpdate() block.
 * These tests simulate the post-lock re-check guards that protect against
 * race conditions when two requests try to process the same payment
 * simultaneously.
 */
test('a second reversal attempt on the same payment fails even when the first just committed', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $service = app(PaymentReversalService::class);
    $actor = User::factory()->create();

    $service->create($payment, ['reason' => 'First reversal', 'notes' => null], requestAs($actor));

    expect(fn () => $service->create($payment->fresh(), ['reason' => 'Second reversal attempt', 'notes' => null], requestAs($actor)))
        ->toThrow(ValidationException::class);

    expect($payment->fresh()->reversal)->not->toBeNull();
    expect(PaymentReversal::where('original_payment_id', $payment->id)->count())->toBe(1);
});

test('a second concurrent refund that would exceed the refundable balance fails with a validation exception', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);
    $payment = paymentFor($invoice, ['amount' => 100000]);

    $service = app(PaymentRefundService::class);
    $actor = User::factory()->create();

    $service->create($payment, [
        'amount' => 80000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'First refund',
        'notes' => null,
    ], requestAs($actor));

    expect(fn () => $service->create($payment->fresh(), [
        'amount' => 30000,
        'refund_method' => Payment::METHOD_CASH,
        'refund_reference' => null,
        'reason' => 'Second refund — would overshoot',
        'notes' => null,
    ], requestAs($actor)))->toThrow(ValidationException::class);

    expect((float) $payment->fresh()->refunds()->sum('amount'))->toBe(80000.0);
});

test('duplicate refund reference is caught even when submitted simultaneously (unique constraint enforces it)', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice1 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment1 = paymentFor($invoice1, ['amount' => 50000]);
    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment2 = paymentFor($invoice2, ['amount' => 50000]);

    $service = app(PaymentRefundService::class);
    $actor = User::factory()->create();

    $service->create($payment1, [
        'amount' => 10000,
        'refund_method' => Payment::METHOD_MOBILE_MONEY,
        'refund_reference' => 'MPESA-CONCURRENCY-001',
        'reason' => 'First',
        'notes' => null,
    ], requestAs($actor));

    expect(fn () => $service->create($payment2, [
        'amount' => 10000,
        'refund_method' => Payment::METHOD_MOBILE_MONEY,
        'refund_reference' => 'MPESA-CONCURRENCY-001',
        'reason' => 'Duplicate reference',
        'notes' => null,
    ], requestAs($actor)))->toThrow(ValidationException::class);
});
