<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('a guest is redirected to login for every department route', function () {
    $department = Department::factory()->create();

    $this->get(route('departments.index'))->assertRedirect(route('login'));
    $this->get(route('departments.create'))->assertRedirect(route('login'));
    $this->get(route('departments.show', $department))->assertRedirect(route('login'));
    $this->get(route('departments.edit', $department))->assertRedirect(route('login'));
});

test('a user without departments.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
});

test('a user without departments.create receives a 403', function () {
    $user = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->get(route('departments.create'))->assertForbidden();
    $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $hospital->id,
        'name' => 'Should Not Exist',
        'code' => 'NOPE',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('departments', ['code' => 'NOPE']);
});

test('a user without departments.update receives a 403', function () {
    $user = userWithRole('pharmacist');
    $department = Department::factory()->create(['name' => 'Untouched']);

    $this->actingAs($user)
        ->put(route('departments.update', $department), [
            'hospital_id' => $department->hospital_id,
            'name' => 'Hacked',
            'code' => $department->code,
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($department->fresh()->name)->toBe('Untouched');
});

test('a user without departments.delete receives a 403', function () {
    $user = userWithRole('pharmacist');
    $department = Department::factory()->create();

    $this->actingAs($user)->delete(route('departments.destroy', $department))->assertForbidden();

    $this->assertDatabaseHas('departments', ['id' => $department->id, 'deleted_at' => null]);
});

test('a user cannot bypass department authorization by visiting the url directly', function () {
    $user = User::factory()->create();
    Role::findOrCreate('nurse');
    $user->assignRole('nurse');

    $department = Department::factory()->create();

    $this->actingAs($user)->get(route('departments.edit', $department))->assertForbidden();
});

test('a super-admin bypasses department permission checks entirely', function () {
    $user = userWithRole('super-admin');
    $department = Department::factory()->create();

    $this->actingAs($user)->get(route('departments.index'))->assertOk();
    $this->actingAs($user)->get(route('departments.create'))->assertOk();
    $this->actingAs($user)->get(route('departments.edit', $department))->assertOk();
});
