<?php

use App\Models\Staff;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk();
});

test('the authenticated user is shared with the dashboard', function () {
    $role = Role::findOrCreate('receptionist');
    $role->givePermissionTo(Permission::findOrCreate('patients.view'));

    $user = User::factory()->create();
    $user->assignRole('receptionist');

    $this->actingAs($user);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.UserName', $user->UserName)
        ->where('auth.user.email', $user->email)
        ->where('auth.roles', ['receptionist'])
        ->where('auth.permissions', ['patients.view'])
        ->missing('auth.user.password')
        ->missing('auth.user.remember_token')
    );
});

test('an authenticated doctor is shared with their staff and practitioner profile', function () {
    Role::findOrCreate('doctor');

    $user = User::factory()->create();
    $user->assignRole('doctor');

    $staff = Staff::factory()->create(['user_id' => $user->id, 'job_title' => 'Doctor']);
    $staff->practitioner()->create(['practitioner_type' => 'doctor', 'status' => 'active']);

    $this->actingAs($user);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.staff.id', $staff->id)
        ->where('auth.user.staff.practitioner.practitioner_type', 'doctor')
    );
});

test('an authenticated receptionist is shared with staff but no practitioner profile', function () {
    Role::findOrCreate('receptionist');

    $user = User::factory()->create();
    $user->assignRole('receptionist');

    $staff = Staff::factory()->create(['user_id' => $user->id, 'job_title' => 'Receptionist']);

    $this->actingAs($user);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.staff.id', $staff->id)
        ->where('auth.user.staff.practitioner', null)
    );
});
