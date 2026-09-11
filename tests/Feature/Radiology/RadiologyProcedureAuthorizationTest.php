<?php

use App\Models\RadiologyProcedure;

test('a guest is redirected to login for every radiology catalog route', function () {
    $procedure = activeRadiologyProcedure();

    $this->get(route('radiology-catalog.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-catalog.create'))->assertRedirect(route('login'));
    $this->get(route('radiology-catalog.show', $procedure))->assertRedirect(route('login'));
    $this->get(route('radiology-catalog.edit', $procedure))->assertRedirect(route('login'));
});

test('a doctor can view the catalog but cannot create, update, or deactivate a procedure', function () {
    $doctor = userWithRole('doctor');
    $procedure = activeRadiologyProcedure();

    $this->actingAs($doctor)->get(route('radiology-catalog.index'))->assertOk();
    $this->actingAs($doctor)->get(route('radiology-catalog.show', $procedure))->assertOk();

    $this->actingAs($doctor)->get(route('radiology-catalog.create'))->assertForbidden();
    $this->actingAs($doctor)->post(route('radiology-catalog.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($doctor)->put(route('radiology-catalog.update', $procedure), ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($doctor)->post(route('radiology-catalog.deactivate', $procedure))->assertForbidden();

    expect($procedure->fresh()->name)->not->toBe('Hijacked');
});

test('a radiology technician can view but not manage the catalog', function () {
    $technician = userWithRole('radiology-technician');
    $procedure = activeRadiologyProcedure();

    $this->actingAs($technician)->get(route('radiology-catalog.index'))->assertOk();

    $this->actingAs($technician)->post(route('radiology-catalog.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($technician)->post(route('radiology-catalog.activate', $procedure))->assertForbidden();
});

test('a receptionist cannot access any radiology catalog route', function () {
    $receptionist = userWithRole('receptionist');
    $procedure = activeRadiologyProcedure();

    $this->actingAs($receptionist)->get(route('radiology-catalog.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('radiology-catalog.show', $procedure))->assertForbidden();
});

test('a hospital-admin can fully manage the radiology catalog over HTTP', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'ADMIN1',
        'name' => 'Admin Created Procedure',
    ])->assertRedirect();

    $procedure = RadiologyProcedure::query()->where('code', 'ADMIN1')->firstOrFail();

    $this->actingAs($admin)->put(route('radiology-catalog.update', $procedure), ['name' => 'Renamed Procedure'])->assertRedirect();
    expect($procedure->fresh()->name)->toBe('Renamed Procedure');

    $this->actingAs($admin)->post(route('radiology-catalog.deactivate', $procedure))->assertRedirect();
    expect($procedure->fresh()->status)->toBe('inactive');

    $this->actingAs($admin)->post(route('radiology-catalog.activate', $procedure))->assertRedirect();
    expect($procedure->fresh()->status)->toBe('active');
});

test('a user without the permission cannot reach a procedure edit page regardless of which id is in the url', function () {
    $doctor = userWithRole('doctor');
    $procedureA = activeRadiologyProcedure();
    $procedureB = activeRadiologyProcedure();

    $this->actingAs($doctor)->get(route('radiology-catalog.edit', $procedureA))->assertForbidden();
    $this->actingAs($doctor)->get(route('radiology-catalog.edit', $procedureB))->assertForbidden();
});

test('a super-admin bypasses radiology catalog permission checks', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('radiology-catalog.store'), ['code' => 'SUPER', 'name' => 'Super Procedure'])->assertRedirect();

    $this->assertDatabaseHas('radiology_procedures', ['code' => 'SUPER']);
});
