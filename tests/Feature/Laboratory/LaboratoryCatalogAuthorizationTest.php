<?php

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;

test('a guest is redirected to login for every laboratory catalog route', function () {
    $test = activeLaboratoryTest();

    $this->get(route('laboratory-tests.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory-tests.create'))->assertRedirect(route('login'));
    $this->get(route('laboratory-tests.show', $test))->assertRedirect(route('login'));
    $this->get(route('laboratory-tests.edit', $test))->assertRedirect(route('login'));
    $this->get(route('laboratory-categories.index'))->assertRedirect(route('login'));
});

test('a doctor can view the catalog but cannot create, update, or deactivate a test', function () {
    $doctor = userWithRole('doctor');
    $test = activeLaboratoryTest();

    $this->actingAs($doctor)->get(route('laboratory-tests.index'))->assertOk();
    $this->actingAs($doctor)->get(route('laboratory-tests.show', $test))->assertOk();

    $this->actingAs($doctor)->get(route('laboratory-tests.create'))->assertForbidden();
    $this->actingAs($doctor)->post(route('laboratory-tests.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($doctor)->put(route('laboratory-tests.update', $test), ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($doctor)->post(route('laboratory-tests.deactivate', $test))->assertForbidden();

    expect($test->fresh()->name)->not->toBe('Hijacked');
});

test('a laboratory technician can view but not manage the catalog or categories', function () {
    $technician = userWithRole('laboratory-technician');
    $test = activeLaboratoryTest();
    $category = LaboratoryTestCategory::factory()->create();

    $this->actingAs($technician)->get(route('laboratory-tests.index'))->assertOk();

    $this->actingAs($technician)->post(route('laboratory-tests.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($technician)->get(route('laboratory-categories.index'))->assertForbidden();
    $this->actingAs($technician)->post(route('laboratory-categories.store'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
    $this->actingAs($technician)->post(route('laboratory-categories.activate', $category))->assertForbidden();
});

test('a receptionist cannot access any laboratory catalog route', function () {
    $receptionist = userWithRole('receptionist');
    $test = activeLaboratoryTest();

    $this->actingAs($receptionist)->get(route('laboratory-tests.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory-tests.show', $test))->assertForbidden();
});

test('a hospital-admin can fully manage tests and categories over HTTP', function () {
    $admin = userWithRole('hospital-admin');
    $category = LaboratoryTestCategory::factory()->create();

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'ADMIN1',
        'name' => 'Admin Created Test',
        'category_id' => $category->id,
    ])->assertRedirect();

    $test = LaboratoryTest::query()->where('code', 'ADMIN1')->firstOrFail();

    $this->actingAs($admin)->put(route('laboratory-tests.update', $test), ['name' => 'Renamed Test'])->assertRedirect();
    expect($test->fresh()->name)->toBe('Renamed Test');

    $this->actingAs($admin)->post(route('laboratory-tests.deactivate', $test))->assertRedirect();
    expect($test->fresh()->status)->toBe('inactive');

    $this->actingAs($admin)->post(route('laboratory-tests.activate', $test))->assertRedirect();
    expect($test->fresh()->status)->toBe('active');
});

test('a user without the permission cannot reach a test edit page regardless of which id is in the url', function () {
    $doctor = userWithRole('doctor');
    $testA = activeLaboratoryTest();
    $testB = activeLaboratoryTest();

    $this->actingAs($doctor)->get(route('laboratory-tests.edit', $testA))->assertForbidden();
    $this->actingAs($doctor)->get(route('laboratory-tests.edit', $testB))->assertForbidden();
});

test('a super-admin bypasses laboratory catalog permission checks', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('laboratory-tests.store'), ['code' => 'SUPER', 'name' => 'Super Test'])->assertRedirect();

    $this->assertDatabaseHas('laboratory_tests', ['code' => 'SUPER']);
});
