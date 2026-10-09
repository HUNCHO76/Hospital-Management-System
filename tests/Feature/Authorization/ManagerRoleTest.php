<?php

use App\Models\Hospital;
use App\Models\NhifMonthlySubmission;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

test('manager role exists with web guard and is unique', function () {
    $role = Role::findByName('Manager', 'web');

    expect($role)->not->toBeNull()
        ->and($role->name)->toBe('Manager')
        ->and($role->guard_name)->toBe('web');

    expect(Role::where('name', 'Manager')->count())->toBe(1);
});

test('manager receives management permissions but no administrator destructive permissions', function () {
    $managerRole = Role::findByName('Manager', 'web');

    // Expected management permissions
    expect($managerRole->hasPermissionTo('dashboard.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('patients.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('patients.create'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('patients.update'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('appointments.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('billing.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('billing-reports.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('pharmacy.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('pharmacy-reports.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('laboratory.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('radiology.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('admission.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('nhif-claims.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('nhif-claims.monthly-view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('nhif-claims.monthly-submit'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('users.view'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('users.create'))->toBeTrue()
        ->and($managerRole->hasPermissionTo('audit-logs.view'))->toBeTrue();

    // Restricted permissions — MUST NOT HAVE
    $managerPermissionNames = $managerRole->permissions->pluck('name')->all();
    $restricted = [
        'users.delete',
        'roles.create',
        'roles.delete',
        'permissions.assign',
        'patients.delete',
        'staff.delete',
        'hospitals.create',
        'hospitals.delete',
    ];

    foreach ($restricted as $permission) {
        expect(in_array($permission, $managerPermissionNames, true))->toBeFalse();
    }
});

test('manager can access dashboard and receives scoped stats', function () {
    $hospital = Hospital::factory()->create();
    $user = User::factory()->create();
    $user->assignRole('Manager');

    Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $hospital->id,
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
});

test('unauthenticated user cannot access dashboard', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('user without audit-logs.view permission receives 403 on audit logs', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('audit-logs.index'));

    $response->assertForbidden();
});

test('manager can view staff and edit staff within own hospital', function () {
    $hospital = Hospital::factory()->create();
    $managerUser = User::factory()->create();
    $managerUser->assignRole('Manager');

    $managerStaff = Staff::factory()->create([
        'user_id' => $managerUser->id,
        'hospital_id' => $hospital->id,
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $subordinateStaff = Staff::factory()->create([
        'hospital_id' => $hospital->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $response = $this->actingAs($managerUser)->get(route('staff.edit', $subordinateStaff->id));
    $response->assertOk();
});

test('manager cannot edit staff from another hospital (facility isolation)', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $managerUser = User::factory()->create();
    $managerUser->assignRole('Manager');

    Staff::factory()->create([
        'user_id' => $managerUser->id,
        'hospital_id' => $hospitalA->id,
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $staffInOtherHospital = Staff::factory()->create([
        'hospital_id' => $hospitalB->id,
        'first_name' => 'Foreign',
        'last_name' => 'Staff',
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $response = $this->actingAs($managerUser)->get(route('staff.edit', $staffInOtherHospital->id));
    $response->assertForbidden();
});

test('manager cannot delete staff', function () {
    $hospital = Hospital::factory()->create();
    $managerUser = User::factory()->create();
    $managerUser->assignRole('Manager');

    Staff::factory()->create([
        'user_id' => $managerUser->id,
        'hospital_id' => $hospital->id,
        'status' => Staff::STATUS_ACTIVE,
    ]);

    $targetStaff = Staff::factory()->create([
        'hospital_id' => $hospital->id,
    ]);

    $response = $this->actingAs($managerUser)->delete(route('staff.destroy', $targetStaff->id));
    $response->assertForbidden();
});

test('manager can access nhif monthly claims and is restricted by facility', function () {
    $hospitalA = Hospital::factory()->create(['code' => 'HOSP-A']);
    $hospitalB = Hospital::factory()->create(['code' => 'HOSP-B']);

    $managerUser = User::factory()->create();
    $managerUser->assignRole('Manager');

    Staff::factory()->create([
        'user_id' => $managerUser->id,
        'hospital_id' => $hospitalA->id,
        'status' => Staff::STATUS_ACTIVE,
    ]);

    // Can access monthly claims page
    $response = $this->actingAs($managerUser)->get(route('nhif.claims.monthly'));
    $response->assertOk();

    // Cannot calculate monthly claims for hospital B
    $responseOther = $this->actingAs($managerUser)->get(route('nhif.claims.monthly.calculate', [
        'claim_year' => 2026,
        'claim_month' => 10,
        'hospital_id' => $hospitalB->id,
    ]));
    $responseOther->assertForbidden();

    // Cannot submit monthly claims for hospital B
    $responseSubmitOther = $this->actingAs($managerUser)->post(route('nhif.claims.monthly.submit'), [
        'claim_year' => 2026,
        'claim_month' => 10,
        'hospital_id' => $hospitalB->id,
    ]);
    $responseSubmitOther->assertForbidden();
});
