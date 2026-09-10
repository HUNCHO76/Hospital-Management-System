<?php

use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientInsurance;
use Illuminate\Database\QueryException;

test('a patient number is generated and unique', function () {
    $first = Patient::factory()->create();
    $second = Patient::factory()->create();

    expect($first->patient_number)->not->toBe($second->patient_number)
        ->and($first->patient_number)->toMatch('/^PT-\d{4}-\d{6}$/');
});

test('a patient number cannot be duplicated', function () {
    $patient = Patient::factory()->create();

    expect(fn () => Patient::factory()->create(['patient_number' => $patient->patient_number]))
        ->toThrow(QueryException::class);
});

test('a patient can have multiple contacts', function () {
    $patient = Patient::factory()->create();
    PatientContact::factory()->count(2)->create(['patient_id' => $patient->id]);

    expect($patient->contacts()->count())->toBe(2);
});

test('a patient can have multiple insurance records', function () {
    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    expect($patient->insurances()->count())->toBe(2);
});
