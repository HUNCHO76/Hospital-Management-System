<?php

use App\Models\Hospital;
use App\Models\User;
use App\Services\CashierSessionService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 59 — cashier session concurrency: duplicate-open guard,
 * simultaneous open attempts serialised correctly.
 */
test('a cashier cannot have two open sessions at the same hospital simultaneously', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    cashierSessionFor($hospital, $cashier);

    // Second open attempt by the same cashier at the same hospital must fail.
    expect(fn () => cashierSessionFor($hospital, $cashier))
        ->toThrow(ValidationException::class);
});

test('the same cashier can open a session at a different hospital when the first is still open', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session1 = cashierSessionFor($hospital1, $cashier);
    $session2 = cashierSessionFor($hospital2, $cashier);

    expect($session1->hospital_id)->toBe($hospital1->id);
    expect($session2->hospital_id)->toBe($hospital2->id);
    expect($session1->session_number)->not->toBe($session2->session_number);
});

test('closing a session allows the cashier to open a new one at the same hospital', function () {
    $hospital = Hospital::factory()->create();
    $cashier = User::factory()->create();

    $session1 = cashierSessionFor($hospital, $cashier);

    app(CashierSessionService::class)->close($session1->fresh(), [
        'actual_closing_balance' => 50000,
        'variance_reason' => null,
    ], requestAs($cashier));

    // Should succeed now that the previous session is closed.
    $session2 = cashierSessionFor($hospital, $cashier);
    expect($session2->status)->toBe('open');
    expect($session2->id)->not->toBe($session1->id);
});
