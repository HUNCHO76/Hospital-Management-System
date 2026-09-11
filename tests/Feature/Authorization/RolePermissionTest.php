<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('a user can be assigned a role', function () {
    $user = User::factory()->create();
    Role::findOrCreate('doctor');

    $user->assignRole('doctor');

    expect($user->hasRole('doctor'))->toBeTrue();
});

test('a user can receive the nurse role', function () {
    $user = User::factory()->create();
    Role::findOrCreate('nurse');

    $user->assignRole('nurse');

    expect($user->hasRole('nurse'))->toBeTrue();
});

test('a role can be removed from a user', function () {
    $user = User::factory()->create();
    Role::findOrCreate('nurse');
    $user->assignRole('nurse');

    $user->removeRole('nurse');

    expect($user->hasRole('nurse'))->toBeFalse();
});

test('a user role can be changed via syncRoles', function () {
    $user = User::factory()->create();
    Role::findOrCreate('doctor');
    Role::findOrCreate('nurse');
    $user->assignRole('doctor');

    $user->syncRoles(['nurse']);

    expect($user->hasRole('nurse'))->toBeTrue()
        ->and($user->hasRole('doctor'))->toBeFalse();
});

test('a user can be checked against any of several roles', function () {
    $user = User::factory()->create();
    Role::findOrCreate('doctor');
    Role::findOrCreate('nurse');
    $user->assignRole('doctor');

    expect($user->hasAnyRole(['doctor', 'nurse']))->toBeTrue()
        ->and($user->hasAnyRole(['receptionist', 'accountant']))->toBeFalse();
});

test('assigning the same role twice does not create a duplicate assignment', function () {
    $user = User::factory()->create();
    Role::findOrCreate('doctor');

    $user->assignRole('doctor');
    $user->assignRole('doctor');

    expect($user->roles()->where('name', 'doctor')->count())->toBe(1);
});

test('creating the same role twice does not create a duplicate role', function () {
    Role::findOrCreate('doctor');
    Role::findOrCreate('doctor');

    expect(Role::query()->where('name', 'doctor')->count())->toBe(1);
});

test('creating the same permission twice does not create a duplicate permission', function () {
    Permission::findOrCreate('patients.view');
    Permission::findOrCreate('patients.view');

    expect(Permission::query()->where('name', 'patients.view')->count())->toBe(1);
});

test('a user inherits permissions granted to their role', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');
    $role = Role::findOrCreate('receptionist');
    $role->givePermissionTo('patients.view');

    $user->assignRole('receptionist');

    expect($user->can('patients.view'))->toBeTrue()
        ->and($user->can('patients.delete'))->toBeFalse();
});

test('a user without the permission is denied', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.delete');

    expect($user->can('patients.delete'))->toBeFalse();
});

test('a user can be granted a direct permission', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');

    $user->givePermissionTo('patients.view');

    expect($user->can('patients.view'))->toBeTrue();
});

test('a user permissions can be synced', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');
    Permission::findOrCreate('patients.create');

    $user->syncPermissions(['patients.view', 'patients.create']);

    expect($user->can('patients.view'))->toBeTrue()
        ->and($user->can('patients.create'))->toBeTrue();
});

test('the permission seeder is idempotent', function () {
    $this->seed(PermissionSeeder::class);
    $countBefore = Permission::count();

    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe($countBefore)->toBeGreaterThan(0);
});

test('the role seeder is idempotent', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $countBefore = Role::count();

    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe($countBefore)->toBe(11);
});

test('doctor can view patients but cannot create users', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $doctor = User::factory()->create();
    $doctor->assignRole('doctor');

    expect($doctor->can('patients.view'))->toBeTrue()
        ->and($doctor->can('users.create'))->toBeFalse();
});

test('receptionist can create patients but cannot access restricted clinical functionality', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');

    expect($receptionist->can('patients.create'))->toBeTrue()
        ->and($receptionist->can('practitioners.view'))->toBeFalse();
});

test('accountant cannot perform clinical modification permissions', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $accountant = User::factory()->create();
    $accountant->assignRole('accountant');

    expect($accountant->can('patient-insurance.view'))->toBeTrue()
        ->and($accountant->can('patients.update'))->toBeFalse()
        ->and($accountant->can('patients.delete'))->toBeFalse();
});

test('hospital-admin does not automatically receive role or permission management access', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $hospitalAdmin = User::factory()->create();
    $hospitalAdmin->assignRole('hospital-admin');

    expect($hospitalAdmin->can('roles.view'))->toBeFalse()
        ->and($hospitalAdmin->can('permissions.assign'))->toBeFalse();
});

test('a super-admin has access to every permission via Gate::before', function () {
    $user = User::factory()->create();
    Role::findOrCreate('super-admin');
    $user->assignRole('super-admin');

    // A permission the super-admin role was never explicitly granted.
    Permission::findOrCreate('some-future-module.action');

    expect($user->can('some-future-module.action'))->toBeTrue();
});

test('a non super-admin does not gain unrelated permissions', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('some-future-module.action');

    expect($user->can('some-future-module.action'))->toBeFalse();
});

test('the super-admin role is granted every seeded permission', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $superAdmin = Role::findByName('super-admin');

    expect($superAdmin->permissions()->count())->toBe(Permission::count());
});
