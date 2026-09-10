<?php

use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientInsurance;
use Illuminate\Support\Carbon;

test('a created patient receives a formatted business number', function () {
    $patient = Patient::factory()->create();

    expect($patient->patient_number)
        ->toMatch('/^PT-\d{4}-\d{6}$/')
        ->toStartWith('PT-'.now()->year.'-');
});

test('patient business numbers increment sequentially', function () {
    $first = Patient::factory()->create();
    $second = Patient::factory()->create();

    $sequenceOf = fn (Patient $p) => (int) substr($p->patient_number, -6);

    expect($sequenceOf($second))->toBe($sequenceOf($first) + 1);
});

test('a patient has many contacts', function () {
    $patient = Patient::factory()->create();
    $contact = PatientContact::factory()->create(['patient_id' => $patient->id]);

    expect($patient->contacts)->toHaveCount(1)
        ->and($patient->contacts->first())->toBeInstanceOf(PatientContact::class)
        ->and($patient->contacts->first()->id)->toBe($contact->id);
});

test('a patient has many insurance records', function () {
    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    expect($patient->insurances)->toHaveCount(1)
        ->and($patient->insurances->first())->toBeInstanceOf(PatientInsurance::class);
});

test('the date of birth is cast to a date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-05-20']);

    expect($patient->date_of_birth)->toBeInstanceOf(Carbon::class)
        ->and($patient->date_of_birth->toDateString())->toBe('1990-05-20');
});

test('a patient is soft deleted rather than removed', function () {
    $patient = Patient::factory()->create();

    $patient->delete();

    expect(Patient::query()->find($patient->id))->toBeNull()
        ->and(Patient::withTrashed()->find($patient->id))->not->toBeNull()
        ->and($patient->fresh()->deleted_at)->not->toBeNull();
});

test('the active scope returns only active patients', function () {
    Patient::factory()->create(['status' => Patient::STATUS_ACTIVE]);
    Patient::factory()->create(['status' => Patient::STATUS_INACTIVE]);

    expect(Patient::query()->active()->count())->toBe(1);
});
