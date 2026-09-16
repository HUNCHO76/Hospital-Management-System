<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Services\ChargeService;
use App\Services\PatientAccountService;

/**
 * The patient financial account foundation (Phase 7, Step 52, spec §6-8):
 * auto-provisioned on first use, one per hospital+patient, a
 * concurrency-safe account number.
 */
test('an account is auto-provisioned the first time a patient is charged, not before', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    expect(PatientAccount::query()->where('hospital_id', $hospital->id)->where('patient_id', $patient->id)->exists())->toBeFalse();

    $charge = chargeFor($hospital, $patient);

    $account = PatientAccount::query()->where('hospital_id', $hospital->id)->where('patient_id', $patient->id)->sole();
    expect($charge->patient_account_id)->toBe($account->id);
    expect($account->status)->toBe(PatientAccount::STATUS_ACTIVE);
    expect($account->currency)->toBe(config('billing.currency'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'billing_account_created']);
});

test('account numbers follow the ACC-YYYY-###### format and are unique', function () {
    $account = accountFor(Hospital::factory()->create(), Patient::factory()->create());

    expect($account->account_number)->toStartWith('ACC-'.now()->year.'-');
    expect(strlen($account->account_number))->toBe(strlen('ACC-2026-000001'));
});

test('a patient has exactly one account per hospital, reused across every charge', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $first = chargeFor($hospital, $patient);
    $second = chargeFor($hospital, $patient);

    expect(PatientAccount::query()->where('hospital_id', $hospital->id)->where('patient_id', $patient->id)->count())->toBe(1);
    expect($first->patient_account_id)->toBe($second->patient_account_id);
});

test('the same patient gets a separate account at a different hospital', function () {
    $patient = Patient::factory()->create();
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $accountA = accountFor($hospitalA, $patient);
    $accountB = accountFor($hospitalB, $patient);

    expect($accountA->id)->not->toBe($accountB->id);
    expect(PatientAccount::query()->where('patient_id', $patient->id)->count())->toBe(2);
});

test('balance sums only POSTED charges; pending, voided, and cancelled charges never count', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $actor = userWithRole('accountant');
    $service = app(ChargeService::class);

    $account = accountFor($hospital, $patient);
    $accounts = app(PatientAccountService::class);

    $posted = chargeFor($hospital, $patient, ['unit_price' => 10000]);
    $service->post($posted, requestAs($actor));

    $pending = chargeFor($hospital, $patient, ['unit_price' => 5000]);

    $voided = chargeFor($hospital, $patient, ['unit_price' => 7000]);
    $service->void($voided, 'Data entry mistake', requestAs($actor));

    $cancelled = chargeFor($hospital, $patient, ['unit_price' => 9000]);
    $service->post($cancelled, requestAs($actor));
    $service->cancel($cancelled, 'Billed in error', requestAs($actor));

    expect($accounts->balance($account->fresh()))->toBe(10000.0);
    expect($accounts->pendingTotal($account->fresh()))->toBe(5000.0);
});
