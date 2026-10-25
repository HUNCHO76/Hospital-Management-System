<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionResolutionService;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Reset cached roles and permissions before each test
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->permA = Permission::findOrCreate('clinical.encounters.create', 'web');
    $this->permB = Permission::findOrCreate('clinical.encounters.view', 'web');
    $this->permC = Permission::findOrCreate('billing.invoices.cancel', 'web');
    $this->permManage = Permission::findOrCreate('permissions.assign', 'web');

    $this->roleDoctor = Role::findOrCreate('doctor', 'web');
    $this->roleDoctor->syncPermissions([$this->permA, $this->permB]);

    $this->roleAdmin = Role::findOrCreate('hospital-admin', 'web');
    $this->roleAdmin->syncPermissions([$this->permA, $this->permB, $this->permC, $this->permManage]);

    $this->roleSuperAdmin = Role::findOrCreate('super-admin', 'web');
});

test('rule 1 & 2 & 3: two users with the same role can have different effective permissions without mutating the role', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $user1->assignRole('doctor');
    $user2->assignRole('doctor');

    // Both start with role permissions
    expect($user1->can('clinical.encounters.create'))->toBeTrue()
        ->and($user2->can('clinical.encounters.create'))->toBeTrue();

    // Deny clinical.encounters.create specifically for user2
    UserPermissionOverride::create([
        'user_id' => $user2->id,
        'permission_id' => $this->permA->id,
        'effect' => UserPermissionOverride::EFFECT_DENY,
        'reason' => 'Restricted by medical board review',
    ]);
    app(PermissionResolutionService::class)->clearUserPermissionCache($user2);

    // user1 still has the permission via doctor role
    expect($user1->can('clinical.encounters.create'))->toBeTrue()
        ->and($user1->hasPermissionTo('clinical.encounters.create'))->toBeTrue();

    // user2 is explicitly blocked
    expect($user2->can('clinical.encounters.create'))->toBeFalse()
        ->and($user2->hasPermissionTo('clinical.encounters.create'))->toBeFalse();

    // But user2 still has clinical.encounters.view from doctor role
    expect($user2->can('clinical.encounters.view'))->toBeTrue();

    // The shared doctor role remains intact with its permissions
    $this->roleDoctor->refresh();
    expect($this->roleDoctor->hasPermissionTo('clinical.encounters.create'))->toBeTrue();

    // A new user assigned doctor role gets the unchanged role permissions
    $user3 = User::factory()->create();
    $user3->assignRole('doctor');
    expect($user3->can('clinical.encounters.create'))->toBeTrue();
});

test('rule 2: explicit allow grants a permission absent from user assigned roles', function () {
    $nurse = User::factory()->create();
    $roleNurse = Role::findOrCreate('nurse', 'web');
    $roleNurse->syncPermissions([$this->permB]); // only view

    $nurse->assignRole('nurse');

    // Nurse initially cannot cancel invoices
    expect($nurse->can('billing.invoices.cancel'))->toBeFalse();

    // Explicitly grant billing.invoices.cancel to this individual nurse
    UserPermissionOverride::create([
        'user_id' => $nurse->id,
        'permission_id' => $this->permC->id,
        'effect' => UserPermissionOverride::EFFECT_ALLOW,
        'reason' => 'Cross-trained night shift cashier cover',
    ]);
    app(PermissionResolutionService::class)->clearUserPermissionCache($nurse);

    // Nurse now has effective permission via explicit allow
    expect($nurse->can('billing.invoices.cancel'))->toBeTrue()
        ->and($nurse->hasPermissionTo('billing.invoices.cancel'))->toBeTrue();

    // All permissions collection includes the granted permission
    $effectiveNames = $nurse->getAllPermissions()->pluck('name')->all();
    expect($effectiveNames)->toContain('billing.invoices.cancel');
});

test('rule 4: resetting an override restores normal inherited role behavior', function () {
    $doctor = User::factory()->create();
    $doctor->assignRole('doctor');

    $override = UserPermissionOverride::create([
        'user_id' => $doctor->id,
        'permission_id' => $this->permA->id,
        'effect' => UserPermissionOverride::EFFECT_DENY,
    ]);
    app(PermissionResolutionService::class)->clearUserPermissionCache($doctor);

    expect($doctor->can('clinical.encounters.create'))->toBeFalse();

    // Delete the override
    $override->delete();
    app(PermissionResolutionService::class)->clearUserPermissionCache($doctor);

    // Doctor returns to inherited permission
    expect($doctor->can('clinical.encounters.create'))->toBeTrue()
        ->and($doctor->hasPermissionTo('clinical.encounters.create'))->toBeTrue();
});

test('gate and can middleware respect explicit denial over super-admin role', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    // Super-admin normally can do anything
    expect($superAdmin->can('billing.invoices.cancel'))->toBeTrue();

    // If an explicit denial is attached to super-admin account
    UserPermissionOverride::create([
        'user_id' => $superAdmin->id,
        'permission_id' => $this->permC->id,
        'effect' => UserPermissionOverride::EFFECT_DENY,
        'reason' => 'Suspended financial access',
    ]);
    app(PermissionResolutionService::class)->clearUserPermissionCache($superAdmin);

    // Explicit denial overrides even super-admin!
    expect(Gate::forUser($superAdmin)->allows('billing.invoices.cancel'))->toBeFalse()
        ->and($superAdmin->can('billing.invoices.cancel'))->toBeFalse();
});

test('authorized administrator can update user permission overrides via API endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('doctor');

    $response = $this->actingAs($admin)->put(route('users.permissions.update', $targetUser->id), [
        'overrides' => [
            [
                'permission_id' => $this->permA->id,
                'effect' => 'deny',
            ],
            [
                'permission_id' => $this->permC->id,
                'effect' => 'allow',
            ],
        ],
        'reason' => 'Administrative reallocation of clinical and billing tasks',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Check database records
    $this->assertDatabaseHas('user_permission_overrides', [
        'user_id' => $targetUser->id,
        'permission_id' => $this->permA->id,
        'effect' => 'deny',
    ]);
    $this->assertDatabaseHas('user_permission_overrides', [
        'user_id' => $targetUser->id,
        'permission_id' => $this->permC->id,
        'effect' => 'allow',
    ]);

    // Check target user effective permissions
    expect($targetUser->can('clinical.encounters.create'))->toBeFalse()
        ->and($targetUser->can('billing.invoices.cancel'))->toBeTrue();
});

test('setting effect to inherit removes the database override record', function () {
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('doctor');

    // Seed existing deny override
    UserPermissionOverride::create([
        'user_id' => $targetUser->id,
        'permission_id' => $this->permA->id,
        'effect' => UserPermissionOverride::EFFECT_DENY,
    ]);

    $response = $this->actingAs($admin)->put(route('users.permissions.update', $targetUser->id), [
        'overrides' => [
            [
                'permission_id' => $this->permA->id,
                'effect' => 'inherit',
            ],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseMissing('user_permission_overrides', [
        'user_id' => $targetUser->id,
        'permission_id' => $this->permA->id,
    ]);

    expect($targetUser->can('clinical.encounters.create'))->toBeTrue();
});

test('administrator can reset all user overrides back to role defaults via delete endpoint', function () {
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $targetUser = User::factory()->create();
    $targetUser->assignRole('doctor');

    UserPermissionOverride::create([
        'user_id' => $targetUser->id,
        'permission_id' => $this->permA->id,
        'effect' => UserPermissionOverride::EFFECT_DENY,
    ]);
    UserPermissionOverride::create([
        'user_id' => $targetUser->id,
        'permission_id' => $this->permC->id,
        'effect' => UserPermissionOverride::EFFECT_ALLOW,
    ]);

    $response = $this->actingAs($admin)->delete(route('users.permissions.reset', $targetUser->id));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('user_permission_overrides', [
        'user_id' => $targetUser->id,
    ]);

    // Restores default inherited behavior
    expect($targetUser->can('clinical.encounters.create'))->toBeTrue()
        ->and($targetUser->can('billing.invoices.cancel'))->toBeFalse();
});

test('unauthorized users cannot update or reset user permission overrides', function () {
    $unauthorizedUser = User::factory()->create();
    $unauthorizedUser->assignRole('doctor');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($unauthorizedUser)->put(route('users.permissions.update', $targetUser->id), [
        'overrides' => [
            [
                'permission_id' => $this->permA->id,
                'effect' => 'allow',
            ],
        ],
    ]);

    $response->assertForbidden();

    $resetResponse = $this->actingAs($unauthorizedUser)->delete(route('users.permissions.reset', $targetUser->id));
    $resetResponse->assertForbidden();
});

test('users cannot modify their own permission overrides to prevent self-escalation', function () {
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $response = $this->actingAs($admin)->put(route('users.permissions.update', $admin->id), [
        'overrides' => [
            [
                'permission_id' => $this->permC->id,
                'effect' => 'allow',
            ],
        ],
    ]);

    $response->assertForbidden();
});

test('non-super-admin cannot grant permissions they do not possess', function () {
    $restrictedAdmin = User::factory()->create();
    // restrictedAdmin only has permManage and permA, NOT permC
    $restrictedRole = Role::findOrCreate('department-lead', 'web');
    $restrictedRole->syncPermissions([$this->permManage, $this->permA]);
    $restrictedAdmin->assignRole('department-lead');

    $targetUser = User::factory()->create();

    $response = $this->actingAs($restrictedAdmin)->put(route('users.permissions.update', $targetUser->id), [
        'overrides' => [
            [
                'permission_id' => $this->permC->id, // permC is NOT held by restrictedAdmin
                'effect' => 'allow',
            ],
        ],
    ]);

    $response->assertSessionHasErrors(['overrides']);
});

test('non-super-admin cannot modify permissions of a super-admin user', function () {
    $hospitalAdmin = User::factory()->create();
    $hospitalAdmin->assignRole('hospital-admin');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $response = $this->actingAs($hospitalAdmin)->put(route('users.permissions.update', $superAdmin->id), [
        'overrides' => [
            [
                'permission_id' => $this->permA->id,
                'effect' => 'deny',
            ],
        ],
    ]);

    $response->assertForbidden();
});

test('permission matrix returns complete structured data for UI rendering', function () {
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $doctor = User::factory()->create();
    $doctor->assignRole('doctor');

    $response = $this->actingAs($admin)->get(route('users.permissions.edit', $doctor->id));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('users/permissions')
        ->has('matrix')
        ->has('matrix.user')
        ->has('matrix.permission_groups')
        ->has('matrix.counts')
        ->where('matrix.user.id', $doctor->id)
    );
});
