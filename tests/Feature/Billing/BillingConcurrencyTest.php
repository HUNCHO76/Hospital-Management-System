<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Services\ChargeService;
use App\Services\PatientAccountService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §27-28): account auto-provisioning and
 * charge state transitions must never produce duplicates or an inconsistent
 * state, regardless of how many overlapping requests attempt them. Mirrors
 * StockConcurrencyTest/DispensingConcurrencyTest's own "simulate repeated
 * attempts" approach.
 */
test('several back-to-back attempts to auto-provision the same patient\'s account create exactly one account', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $service = app(PatientAccountService::class);
    $actor = userWithRole('accountant');

    for ($i = 0; $i < 5; $i++) {
        $service->ensureAccountExists($hospital->id, $patient->id, requestAs($actor));
    }

    expect(PatientAccount::query()->where('hospital_id', $hospital->id)->where('patient_id', $patient->id)->count())->toBe(1);
});

test('several charges created back-to-back for a brand-new patient all land on the same single auto-provisioned account', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        chargeFor($hospital, $patient);
    }

    expect(PatientAccount::query()->where('hospital_id', $hospital->id)->where('patient_id', $patient->id)->count())->toBe(1);
    expect(Charge::query()->where('patient_id', $patient->id)->count())->toBe(5);
});

test('several account numbers generated back-to-back are all unique', function () {
    $service = app(PatientAccountService::class);
    $actor = userWithRole('accountant');
    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $account = $service->ensureAccountExists(Hospital::factory()->create()->id, Patient::factory()->create()->id, requestAs($actor));
        $numbers[] = $account->account_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
});

test('only one of several back-to-back attempts to post the same charge succeeds', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->post($charge->fresh(), requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(Charge::query()->count())->toBe(1);
});

test('only one of several back-to-back attempts to cancel the same posted charge succeeds', function () {
    $charge = chargeFor(Hospital::factory()->create(), Patient::factory()->create());
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);
    $service->post($charge, requestAs($actor));

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->cancel($charge->fresh(), 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});
