<?php

use App\Models\CashierSession;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashierSessionService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 59 — cashier session lifecycle: open, collect, reconcile,
 * close; balance formula; variance handling; session-number generation.
 */
test('opening a session creates a CashierSession with status open', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 25000]);

    expect($session)->toBeInstanceOf(CashierSession::class);
    expect($session->status)->toBe(CashierSession::STATUS_OPEN);
    expect((float) $session->opening_balance)->toBe(25000.0);
    expect($session->hospital_id)->toBe($hospital->id);
    expect($session->cashier_id)->toBe($cashier->id);
    expect($session->session_number)->toStartWith('CSN-');
    expect($session->opened_at)->not->toBeNull();
    expect($session->closed_at)->toBeNull();
});

test('opening a session with zero opening balance is allowed', function () {
    $hospital = Hospital::factory()->create();
    $session = cashierSessionFor($hospital, overrides: ['opening_balance' => 0]);

    expect((float) $session->opening_balance)->toBe(0.0);
    expect($session->status)->toBe(CashierSession::STATUS_OPEN);
});

test('opening a session with a negative opening balance is rejected', function () {
    $hospital = Hospital::factory()->create();

    expect(fn () => cashierSessionFor($hospital, overrides: ['opening_balance' => -1000]))
        ->toThrow(ValidationException::class);
});

test('session number is unique across concurrent sessions', function () {
    $hospital = Hospital::factory()->create();

    $session1 = cashierSessionFor($hospital, User::factory()->create());
    $session2 = cashierSessionFor($hospital, User::factory()->create());

    expect($session1->session_number)->not->toBe($session2->session_number);
});

test('getCollectionSummary returns payment-method totals from real payment records', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 10000]);

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    // A payment received by this cashier after the session opened.
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 30000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $summary = app(CashierSessionService::class)->getCollectionSummary($session->fresh());

    expect($summary['paymentTotals'][Payment::METHOD_CASH])->toBe(30000.0);
    expect($summary['netCashCollected'])->toBe(30000.0);
});

test('closing a session records expected and actual balance and zero variance', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 10000]);

    // No payments → expected closing = opening balance
    $closed = app(CashierSessionService::class)->close($session, [
        'actual_closing_balance' => 10000,
        'variance_reason' => null,
    ], requestAs($cashier));

    expect($closed->status)->toBe(CashierSession::STATUS_CLOSED);
    expect((float) $closed->expected_closing_balance)->toBe(10000.0);
    expect((float) $closed->actual_closing_balance)->toBe(10000.0);
    expect((float) $closed->variance)->toBe(0.0);
    expect($closed->variance_reason)->toBeNull();
    expect($closed->closed_at)->not->toBeNull();
});

test('closing a session with non-zero variance records the variance and requires a reason', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 10000]);

    // No payments → expected = 10000; actual = 9500 → variance = -500
    expect(fn () => app(CashierSessionService::class)->close($session, [
        'actual_closing_balance' => 9500,
        'variance_reason' => '',
    ], requestAs($cashier)))->toThrow(ValidationException::class);

    // With reason provided it should succeed.
    $closed = app(CashierSessionService::class)->close($session->fresh(), [
        'actual_closing_balance' => 9500,
        'variance_reason' => 'Short change incident during lunch',
    ], requestAs($cashier));

    expect((float) $closed->variance)->toBe(-500.0);
    expect($closed->variance_reason)->toBe('Short change incident during lunch');
});

test('closing a session updates expected_closing_balance from real payment records', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier, ['opening_balance' => 5000]);

    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 20000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 15000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    // expected = 5000 + 15000 = 20000
    $closed = app(CashierSessionService::class)->close($session->fresh(), [
        'actual_closing_balance' => 20000,
        'variance_reason' => null,
    ], requestAs($cashier));

    expect((float) $closed->expected_closing_balance)->toBe(20000.0);
    expect((float) $closed->variance)->toBe(0.0);
});

test('closing an already-closed session is rejected', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session = cashierSessionFor($hospital, $cashier);
    app(CashierSessionService::class)->close($session->fresh(), [
        'actual_closing_balance' => 50000,
        'variance_reason' => null,
    ], requestAs($cashier));

    expect(fn () => app(CashierSessionService::class)->close($session->fresh(), [
        'actual_closing_balance' => 50000,
        'variance_reason' => null,
    ], requestAs($cashier)))->toThrow(ValidationException::class);
});
