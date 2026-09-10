<?php

use App\Models\Patient;
use App\Models\User;

test('a guest is redirected to login for every patient route', function () {
    $patient = Patient::factory()->create();

    $this->get(route('patients.index'))->assertRedirect(route('login'));
    $this->get(route('patients.create'))->assertRedirect(route('login'));
    $this->get(route('patients.show', $patient))->assertRedirect(route('login'));
    $this->get(route('patients.edit', $patient))->assertRedirect(route('login'));
});

test('a user without patients.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('patients.index'))->assertForbidden();
});

test('a user without patients.create receives a 403', function () {
    $user = userWithRole('pharmacist');

    $this->actingAs($user)->get(route('patients.create'))->assertForbidden();
    $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Should',
        'last_name' => 'NotExist',
        'gender' => 'male',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('patients', ['first_name' => 'Should', 'last_name' => 'NotExist']);
});

test('a user without patients.update receives a 403', function () {
    $user = userWithRole('pharmacist');
    $patient = Patient::factory()->create(['occupation' => 'Untouched']);

    $this->actingAs($user)
        ->put(route('patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'gender' => $patient->gender,
            'status' => 'active',
            'occupation' => 'Hacked',
        ])
        ->assertForbidden();

    expect($patient->fresh()->occupation)->toBe('Untouched');
});

test('a user without patients.delete receives a 403', function () {
    $user = userWithRole('pharmacist');
    $patient = Patient::factory()->create();

    $this->actingAs($user)->delete(route('patients.destroy', $patient))->assertForbidden();

    $this->assertDatabaseHas('patients', ['id' => $patient->id, 'deleted_at' => null]);
});

test('a receptionist can view, create and update patients but not delete them', function () {
    $user = userWithRole('receptionist');
    $patient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.index'))->assertOk();
    $this->actingAs($user)->get(route('patients.create'))->assertOk();
    $this->actingAs($user)
        ->put(route('patients.update', $patient), [
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'gender' => $patient->gender,
            'status' => 'active',
        ])
        ->assertRedirect(route('patients.show', $patient));
    $this->actingAs($user)->delete(route('patients.destroy', $patient))->assertForbidden();
});

test('a super-admin bypasses patient permission checks entirely', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.index'))->assertOk();
    $this->actingAs($user)->get(route('patients.create'))->assertOk();
    $this->actingAs($user)->get(route('patients.show', $patient))->assertOk();
    $this->actingAs($user)->get(route('patients.edit', $patient))->assertOk();
});
