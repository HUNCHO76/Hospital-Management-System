<?php

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTestParameter;
use App\Models\Patient;
use App\Services\LaboratoryReferenceRangeService;

// ── selectForPatient ─────────────────────────────────────────────────────────

test('selects any-sex adult range when patient has no sex-specific range', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 4.0, 'high' => 11.0,
    ]);

    $patient = Patient::factory()->create([
        'gender' => 'male',
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect($range)->not->toBeNull();
    expect((float) $range->low)->toBe(4.0);
});

test('prefers sex-specific range over any-sex range for same parameter', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 12.0, 'high' => 16.0,
    ]);
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 13.5, 'high' => 17.5,
    ]);

    $patient = Patient::factory()->create([
        'gender' => 'male',
        'date_of_birth' => now()->subYears(25)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect((float) $range->low)->toBe(13.5);
});

test('selects female range for female patient over any-sex range', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 12.0, 'high' => 16.0,
    ]);
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 11.5, 'high' => 15.5,
    ]);

    $patient = Patient::factory()->create([
        'gender' => 'female',
        'date_of_birth' => now()->subYears(28)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect((float) $range->low)->toBe(11.5);
});

test('selects narrower age band over wider when both match', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    // Broad band: all ages
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 0, 'age_max_days' => null,
        'low' => 100.0, 'high' => 500.0,
    ]);
    // Narrow band: children 1–11 years (365–4379 days)
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379,
        'low' => 5.0, 'high' => 15.0,
    ]);

    // 5-year-old patient: 5 * 365 = 1825 days
    $patient = Patient::factory()->create([
        'gender' => 'any',
        'date_of_birth' => now()->subDays(1825)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect((float) $range->low)->toBe(5.0);
});

test('returns null when no ranges are configured for the parameter', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    $patient = Patient::factory()->create([
        'gender' => 'male',
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect($range)->toBeNull();
});

test('returns null when no range matches the patient age', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    // Adults-only range
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 4.0, 'high' => 11.0,
    ]);

    // Neonate patient: 5 days old
    $patient = Patient::factory()->create([
        'gender' => 'male',
        'date_of_birth' => now()->subDays(5)->toDateString(),
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect($range)->toBeNull();
});

test('patient with no date_of_birth matches only fully-unbounded range', function () {
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    // Unbounded on both sides
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => null, 'age_max_days' => null,
        'low' => 4.0, 'high' => 11.0,
    ]);
    // Age-bounded (should not match when DOB unknown)
    LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 4.5, 'high' => 12.0,
    ]);

    $patient = Patient::factory()->create([
        'gender' => 'male',
        'date_of_birth' => null,
    ]);

    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($parameter, $patient);

    expect($range)->not->toBeNull();
    expect((float) $range->low)->toBe(4.0);
});

// ── CRUD + audit ─────────────────────────────────────────────────────────────

test('creating a reference range records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    $range = app(LaboratoryReferenceRangeService::class)->create($parameter, [
        'sex' => 'any', 'age_min_days' => null, 'age_max_days' => null,
        'low' => 4.0, 'high' => 11.0,
    ], requestAs($admin));

    expect($range->id)->toBeInt();
    expect((float) $range->low)->toEqual(4.0);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'laboratory_reference_range_created',
        'auditable_id' => $range->id,
    ]);
});

test('deleting a reference range records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();
    $range = LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create();
    $rangeId = $range->id;

    app(LaboratoryReferenceRangeService::class)->delete($range, requestAs($admin));

    $this->assertDatabaseMissing('laboratory_reference_ranges', ['id' => $rangeId]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_reference_range_deleted']);
});

// ── HTTP routes ──────────────────────────────────────────────────────────────

test('hospital-admin can add a reference range via the route', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    $this->actingAs($admin)
        ->post(route('laboratory-reference-ranges.store', $parameter), [
            'sex' => 'any',
            'low' => 4.0,
            'high' => 11.0,
        ])
        ->assertRedirect(route('laboratory-tests.show', $test));

    $this->assertDatabaseHas('laboratory_reference_ranges', [
        'laboratory_test_parameter_id' => $parameter->id,
        'sex' => 'any',
    ]);
});

test('hospital-admin can delete a reference range via the route', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();
    $range = LaboratoryReferenceRange::factory()->for($parameter, 'parameter')->create();

    $this->actingAs($admin)
        ->delete(route('laboratory-reference-ranges.destroy', [$parameter, $range]))
        ->assertRedirect(route('laboratory-tests.show', $test));

    $this->assertDatabaseMissing('laboratory_reference_ranges', ['id' => $range->id]);
});

test('a user without laboratory-test-parameters.manage is denied range creation', function () {
    $technician = userWithRole('laboratory-technician');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    $this->actingAs($technician)
        ->post(route('laboratory-reference-ranges.store', $parameter), [
            'sex' => 'any', 'low' => 4.0, 'high' => 11.0,
        ])
        ->assertForbidden();
});

test('deleting a range from a different parameter returns 404', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameterA = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();
    $parameterB = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();
    $rangeForB = LaboratoryReferenceRange::factory()->for($parameterB, 'parameter')->create();

    $this->actingAs($admin)
        ->delete(route('laboratory-reference-ranges.destroy', [$parameterA, $rangeForB]))
        ->assertNotFound();
});
