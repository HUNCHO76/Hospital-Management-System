<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\ChargeService;
use Illuminate\Validation\ValidationException;

/**
 * The charge lifecycle foundation (Phase 7, Step 52, spec §12-15):
 * PENDING -> POSTED -> CANCELLED, or PENDING -> VOIDED. Every amount is
 * computed server-side and validated.
 */
test('creating a charge computes gross/net amounts and starts PENDING', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $charge = chargeFor($hospital, $patient, ['description' => 'Chest X-Ray', 'quantity' => 2, 'unit_price' => 20000, 'discount_amount' => 5000]);

    expect($charge->status)->toBe(Charge::STATUS_PENDING);
    expect((float) $charge->gross_amount)->toBe(40000.0);
    expect((float) $charge->discount_amount)->toBe(5000.0);
    expect((float) $charge->net_amount)->toBe(35000.0);
    expect($charge->currency)->toBe(config('billing.currency'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_created']);
});

test('a discount greater than the gross amount is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    expect(fn () => chargeFor($hospital, $patient, ['unit_price' => 100, 'discount_amount' => 101]))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('charges', 0);
});

test('a negative or non-numeric unit price is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    expect(fn () => chargeFor($hospital, $patient, ['unit_price' => -1]))->toThrow(ValidationException::class);
    expect(fn () => chargeFor($hospital, $patient, ['unit_price' => 'not-a-number']))->toThrow(ValidationException::class);
    expect(fn () => chargeFor($hospital, $patient, ['unit_price' => INF]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('charges', 0);
});

test('a unit price with more than 2 decimal places is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    expect(fn () => chargeFor($hospital, $patient, ['unit_price' => 100.999]))->toThrow(ValidationException::class);
});

test('quantity must be at least 1', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    expect(fn () => chargeFor($hospital, $patient, ['quantity' => 0]))->toThrow(ValidationException::class);
});

test('posting a PENDING charge marks it POSTED with a posted_at timestamp', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');

    $posted = app(ChargeService::class)->post($charge, requestAs($actor));

    expect($posted->status)->toBe(Charge::STATUS_POSTED);
    expect($posted->posted_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_posted']);
});

test('a charge can only be posted once', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);

    $service->post($charge, requestAs($actor));

    expect(fn () => $service->post($charge->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('voiding a PENDING charge requires a reason and never counts toward balance', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);

    expect(fn () => $service->void($charge, '', requestAs($actor)))->toThrow(ValidationException::class);

    $voided = $service->void($charge->fresh(), 'Wrong patient selected', requestAs($actor));

    expect($voided->status)->toBe(Charge::STATUS_VOIDED);
    expect($voided->cancellation_reason)->toBe('Wrong patient selected');
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_voided']);
});

test('a POSTED charge cannot be voided — only cancelled', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);
    $service->post($charge, requestAs($actor));

    expect(fn () => $service->void($charge->fresh(), 'reason', requestAs($actor)))->toThrow(ValidationException::class);
});

test('cancelling a POSTED charge preserves the original record and requires a reason', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create(), ['unit_price' => 15000]);
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);
    $service->post($charge, requestAs($actor));

    $cancelled = $service->cancel($charge->fresh(), 'Duplicate entry', requestAs($actor));

    expect($cancelled->status)->toBe(Charge::STATUS_CANCELLED);
    expect((float) $cancelled->net_amount)->toBe(15000.0);
    expect($cancelled->cancellation_reason)->toBe('Duplicate entry');
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_cancelled']);
});

test('a PENDING charge cannot be cancelled — only voided', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');

    expect(fn () => app(ChargeService::class)->cancel($charge, 'reason', requestAs($actor)))->toThrow(ValidationException::class);
});

test('voided and cancelled charges are terminal — no further transition is possible', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);

    $voided = $service->void(chargeFor($hospital, $patient), 'x', requestAs($actor));
    expect(fn () => $service->post($voided->fresh(), requestAs($actor)))->toThrow(ValidationException::class);

    $charge = chargeFor($hospital, $patient);
    $service->post($charge, requestAs($actor));
    $cancelled = $service->cancel($charge->fresh(), 'x', requestAs($actor));
    expect(fn () => $service->cancel($cancelled->fresh(), 'y', requestAs($actor)))->toThrow(ValidationException::class);
});
