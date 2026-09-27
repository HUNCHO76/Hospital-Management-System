<?php

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestParameter;
use App\Services\LaboratoryTestParameterService;
use Database\Seeders\LaboratoryTestParameterSeeder;
use Database\Seeders\LaboratoryTestSeeder;

// ── Service: CRUD + audit ────────────────────────────────────────────────────

test('creating a parameter records an audit event and sets defaults', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();

    $parameter = app(LaboratoryTestParameterService::class)->create($test, [
        'code' => 'HB',
        'name' => 'Hemoglobin',
        'unit' => 'g/dL',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'decimal_places' => 1,
        'display_order' => 1,
    ], requestAs($admin));

    expect($parameter->code)->toBe('HB');
    expect($parameter->laboratory_test_id)->toBe($test->id);
    expect($parameter->active)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'laboratory_test_parameter_created',
        'auditable_id' => $parameter->id,
    ]);
});

test('updating a parameter records an audit event', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    app(LaboratoryTestParameterService::class)->update($parameter, ['name' => 'Updated Name'], requestAs($admin));

    expect($parameter->fresh()->name)->toBe('Updated Name');
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'laboratory_test_parameter_updated',
        'auditable_id' => $parameter->id,
    ]);
});

test('deleting an unreferenced parameter succeeds', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();
    $parameterId = $parameter->id;

    app(LaboratoryTestParameterService::class)->delete($parameter, requestAs($admin));

    $this->assertDatabaseMissing('laboratory_test_parameters', ['id' => $parameterId]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_test_parameter_deleted']);
});

test('duplicate code on same test is rejected by the form request', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create(['code' => 'HB']);

    $this->actingAs($admin)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'HB', 'name' => 'Duplicate', 'data_type' => 'numeric', 'display_order' => 0,
        ])
        ->assertSessionHasErrors('code');

    expect(LaboratoryTestParameter::where('laboratory_test_id', $test->id)->count())->toBe(1);
});

test('same code is allowed on parameters belonging to different tests', function () {
    $admin = userWithRole('hospital-admin');
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();

    $paramA = app(LaboratoryTestParameterService::class)->create($testA, [
        'code' => 'RES', 'name' => 'Result A',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
        'options_json' => ['positive', 'negative'],
        'display_order' => 1,
    ], requestAs($admin));

    $paramB = app(LaboratoryTestParameterService::class)->create($testB, [
        'code' => 'RES', 'name' => 'Result B',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_OPTION,
        'options_json' => ['positive', 'negative'],
        'display_order' => 1,
    ], requestAs($admin));

    expect($paramA->code)->toBe('RES');
    expect($paramB->code)->toBe('RES');
});

// ── HTTP routes ──────────────────────────────────────────────────────────────

test('hospital-admin can add a parameter via the route', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();

    $this->actingAs($admin)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'WBC',
            'name' => 'White Blood Cell Count',
            'unit' => '10^9/L',
            'data_type' => 'numeric',
            'decimal_places' => 1,
            'display_order' => 1,
        ])
        ->assertRedirect(route('laboratory-tests.show', $test));

    $this->assertDatabaseHas('laboratory_test_parameters', [
        'laboratory_test_id' => $test->id,
        'code' => 'WBC',
    ]);
});

test('laboratory-supervisor can add a parameter via the route', function () {
    $supervisor = userWithRole('laboratory-supervisor');
    $test = activeLaboratoryTest();

    $this->actingAs($supervisor)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'PLT',
            'name' => 'Platelet Count',
            'data_type' => 'numeric',
            'display_order' => 1,
        ])
        ->assertRedirect(route('laboratory-tests.show', $test));

    $this->assertDatabaseHas('laboratory_test_parameters', [
        'laboratory_test_id' => $test->id,
        'code' => 'PLT',
    ]);
});

test('laboratory-technician is denied adding parameters', function () {
    $technician = userWithRole('laboratory-technician');
    $test = activeLaboratoryTest();

    $this->actingAs($technician)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'HB', 'name' => 'Hemoglobin', 'data_type' => 'numeric', 'display_order' => 1,
        ])
        ->assertForbidden();
});

test('hospital-admin can update a parameter via the route', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create(['name' => 'Old Name']);

    $this->actingAs($admin)
        ->put(route('laboratory-test-parameters.update', [$test, $parameter]), [
            'name' => 'New Name',
        ])
        ->assertRedirect(route('laboratory-tests.show', $test));

    expect($parameter->fresh()->name)->toBe('New Name');
});

test('hospital-admin can delete a parameter via the route', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    $parameter = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create();

    $this->actingAs($admin)
        ->delete(route('laboratory-test-parameters.destroy', [$test, $parameter]))
        ->assertRedirect(route('laboratory-tests.show', $test));

    $this->assertDatabaseMissing('laboratory_test_parameters', ['id' => $parameter->id]);
});

test('updating a parameter from a different test returns 404', function () {
    $admin = userWithRole('hospital-admin');
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();
    $parameterForB = LaboratoryTestParameter::factory()->for($testB, 'laboratoryTest')->create();

    $this->actingAs($admin)
        ->put(route('laboratory-test-parameters.update', [$testA, $parameterForB]), ['name' => 'X'])
        ->assertNotFound();
});

// ── Validation ───────────────────────────────────────────────────────────────

test('code is required to create a parameter', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();

    $this->actingAs($admin)
        ->post(route('laboratory-test-parameters.store', $test), [
            'name' => 'Missing Code',
            'data_type' => 'numeric',
            'display_order' => 1,
        ])
        ->assertSessionHasErrors('code');
});

test('data_type must be one of the allowed values', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();

    $this->actingAs($admin)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'X', 'name' => 'Test', 'data_type' => 'invalid', 'display_order' => 0,
        ])
        ->assertSessionHasErrors('data_type');
});

test('duplicate code within the same test is rejected by validation', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest();
    LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create(['code' => 'DUPE']);

    $this->actingAs($admin)
        ->post(route('laboratory-test-parameters.store', $test), [
            'code' => 'DUPE', 'name' => 'Duplicate', 'data_type' => 'numeric', 'display_order' => 0,
        ])
        ->assertSessionHasErrors('code');
});

// ── Seeder idempotency ────────────────────────────────────────────────────────

test('LaboratoryTestParameterSeeder is idempotent on the live catalog', function () {
    $seeder = new LaboratoryTestSeeder;
    $seeder->run();

    $paramSeeder = new LaboratoryTestParameterSeeder;
    $paramSeeder->run();

    // Running twice must not create duplicates
    $paramSeeder->run();

    // HB is a single-parameter test — there must be exactly one HB parameter row
    $hbTest = LaboratoryTest::where('code', 'HB')->firstOrFail();
    expect(LaboratoryTestParameter::where('laboratory_test_id', $hbTest->id)->count())->toBe(1);
});
