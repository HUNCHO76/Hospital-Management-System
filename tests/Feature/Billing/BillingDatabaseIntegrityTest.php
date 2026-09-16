<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Database integrity for the billing module (Phase 7, Step 52, spec §38-39),
 * mirroring StockDatabaseIntegrityTest's own style.
 */
test('the billing tables exist with their expected columns', function () {
    expect(Schema::hasTable('patient_accounts'))->toBeTrue();
    expect(Schema::hasTable('charges'))->toBeTrue();

    expect(Schema::hasColumns('patient_accounts', ['hospital_id', 'patient_id', 'account_number', 'status', 'currency']))->toBeTrue();
    expect(Schema::hasColumns('charges', [
        'charge_number', 'hospital_id', 'patient_account_id', 'patient_id', 'encounter_id', 'service_id', 'source_type', 'source_id',
        'service_code_snapshot', 'description', 'notes', 'quantity', 'unit_price', 'gross_amount', 'discount_amount', 'net_amount',
        'currency', 'status', 'charged_at',
    ]))->toBeTrue();
});

test('a hospital may only have one patient account per patient', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    accountFor($hospital, $patient);

    expect(fn () => PatientAccount::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id]))
        ->toThrow(QueryException::class);
});

test('account numbers are globally unique', function () {
    $account = accountFor(Hospital::factory()->create(), Patient::factory()->create());

    expect(fn () => PatientAccount::factory()->create(['account_number' => $account->account_number]))
        ->toThrow(QueryException::class);
});
