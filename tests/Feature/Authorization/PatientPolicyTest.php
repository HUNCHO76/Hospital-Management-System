<?php

use App\Models\Patient;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('a user with the patients.view permission can view a patient', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');
    $user->givePermissionTo('patients.view');

    $patient = Patient::factory()->create();

    expect($user->can('view', $patient))->toBeTrue();
});

test('a user without the patients.update permission cannot update a patient', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.update');

    $patient = Patient::factory()->create();

    expect($user->can('update', $patient))->toBeFalse();
});

test('a user with the patients.delete permission can delete a patient', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.delete');
    $user->givePermissionTo('patients.delete');

    $patient = Patient::factory()->create();

    expect($user->can('delete', $patient))->toBeTrue();
});

test('a super-admin can perform any patient policy ability via Gate::before', function () {
    $user = User::factory()->create();
    Role::findOrCreate('super-admin');
    $user->assignRole('super-admin');

    $patient = Patient::factory()->create();

    expect($user->can('delete', $patient))->toBeTrue();
});
