<?php

use App\Models\Medication;

test('a guest is redirected to login for every medication catalog route', function () {
    $medication = activeMedication();

    $this->get(route('medications.index'))->assertRedirect(route('login'));
    $this->get(route('medications.create'))->assertRedirect(route('login'));
    $this->get(route('medications.show', $medication))->assertRedirect(route('login'));
    $this->get(route('medications.edit', $medication))->assertRedirect(route('login'));
});

test('a doctor can view the catalog but cannot create, update, or deactivate a medication', function () {
    $doctor = userWithRole('doctor');
    $medication = activeMedication();

    $this->actingAs($doctor)->get(route('medications.index'))->assertOk();
    $this->actingAs($doctor)->get(route('medications.show', $medication))->assertOk();

    $this->actingAs($doctor)->get(route('medications.create'))->assertForbidden();
    $this->actingAs($doctor)->post(route('medications.store'), ['code' => 'X', 'generic_name' => 'X'])->assertForbidden();
    $this->actingAs($doctor)->put(route('medications.update', $medication), ['generic_name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($doctor)->post(route('medications.deactivate', $medication))->assertForbidden();

    expect($medication->fresh()->generic_name)->not->toBe('Hijacked');
});

test('a pharmacist can view but not manage the catalog', function () {
    $pharmacist = userWithRole('pharmacist');
    $medication = activeMedication();

    $this->actingAs($pharmacist)->get(route('medications.index'))->assertOk();

    $this->actingAs($pharmacist)->post(route('medications.store'), ['code' => 'X', 'generic_name' => 'X'])->assertForbidden();
    $this->actingAs($pharmacist)->post(route('medications.activate', $medication))->assertForbidden();
});

test('a receptionist cannot access any medication catalog route', function () {
    $receptionist = userWithRole('receptionist');
    $medication = activeMedication();

    $this->actingAs($receptionist)->get(route('medications.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('medications.show', $medication))->assertForbidden();
});

test('a hospital-admin can fully manage the medication catalog over HTTP', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('medications.store'), [
        'code' => 'ADMIN1',
        'generic_name' => 'Admin Created Medication',
    ])->assertRedirect();

    $medication = Medication::query()->where('code', 'ADMIN1')->firstOrFail();

    $this->actingAs($admin)->put(route('medications.update', $medication), ['generic_name' => 'Renamed Medication'])->assertRedirect();
    expect($medication->fresh()->generic_name)->toBe('Renamed Medication');

    $this->actingAs($admin)->post(route('medications.deactivate', $medication))->assertRedirect();
    expect($medication->fresh()->status)->toBe('inactive');

    $this->actingAs($admin)->post(route('medications.activate', $medication))->assertRedirect();
    expect($medication->fresh()->status)->toBe('active');
});

test('a user without the permission cannot reach a medication edit page regardless of which id is in the url', function () {
    $doctor = userWithRole('doctor');
    $medicationA = activeMedication();
    $medicationB = activeMedication();

    $this->actingAs($doctor)->get(route('medications.edit', $medicationA))->assertForbidden();
    $this->actingAs($doctor)->get(route('medications.edit', $medicationB))->assertForbidden();
});

test('a super-admin bypasses medication catalog permission checks', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('medications.store'), ['code' => 'SUPER', 'generic_name' => 'Super Medication'])->assertRedirect();

    $this->assertDatabaseHas('medications', ['code' => 'SUPER']);
});
