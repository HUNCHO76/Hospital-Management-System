<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every staff route', function () {
    $staff = Staff::factory()->create();

    $this->get(route('staff.index'))->assertRedirect(route('login'));
    $this->get(route('staff.create'))->assertRedirect(route('login'));
    $this->get(route('staff.show', $staff))->assertRedirect(route('login'));
    $this->get(route('staff.edit', $staff))->assertRedirect(route('login'));
});

test('a user without staff.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertForbidden();
});

test('a user without staff.create receives a 403', function () {
    $user = userWithRole('doctor');
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->get(route('staff.create'))->assertForbidden();
    $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospital->id,
        'first_name' => 'Should',
        'last_name' => 'NotExist',
        'gender' => 'male',
        'job_title' => 'Nurse',
        'employment_type' => 'full_time',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('staff', ['first_name' => 'Should', 'last_name' => 'NotExist']);
});

test('a user without staff.update receives a 403', function () {
    $user = userWithRole('doctor');
    $staff = Staff::factory()->create(['job_title' => 'Untouched']);

    $this->actingAs($user)
        ->put(route('staff.update', $staff), [
            'hospital_id' => $staff->hospital_id,
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'gender' => $staff->gender,
            'job_title' => 'Hacked',
            'employment_type' => $staff->employment_type,
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($staff->fresh()->job_title)->toBe('Untouched');
});

test('a user without staff.delete receives a 403', function () {
    $user = userWithRole('doctor');
    $staff = Staff::factory()->create();

    $this->actingAs($user)->delete(route('staff.destroy', $staff))->assertForbidden();

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'deleted_at' => null]);
});

/**
 * Mandatory cross-hospital security test (spec §44): a hospital-admin for
 * Hospital A must never be able to view, edit, or otherwise reach a staff
 * member belonging to Hospital B, including by direct URL access.
 */
test('a hospital-admin cannot access another hospital staff', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $staffA = Staff::factory()->create(['hospital_id' => $hospitalA->id]);
    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('staff.show', $staffA))->assertOk();

    $this->actingAs($adminUser)->get(route('staff.show', $staffB))->assertForbidden();
    $this->actingAs($adminUser)->get(route('staff.edit', $staffB))->assertForbidden();
    $this->actingAs($adminUser)
        ->put(route('staff.update', $staffB), [
            'hospital_id' => $hospitalB->id,
            'first_name' => 'Hijacked',
            'last_name' => $staffB->last_name,
            'gender' => $staffB->gender,
            'job_title' => $staffB->job_title,
            'employment_type' => $staffB->employment_type,
            'status' => 'active',
        ])
        ->assertForbidden();
    $this->actingAs($adminUser)->delete(route('staff.destroy', $staffB))->assertForbidden();

    expect($staffB->fresh()->first_name)->not->toBe('Hijacked');
});

test('a hospital-admin staff list only shows their own hospital', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    Staff::factory()->create(['hospital_id' => $hospitalA->id]);
    Staff::factory()->create(['hospital_id' => $hospitalB->id]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    // 2 staff belong to Hospital A: the pre-created one plus the admin's own staff record.
    $this->actingAs($adminUser)
        ->get(route('staff.index'))
        ->assertInertia(fn ($page) => $page->where('staff.total', 2));
});

test('a super-admin bypasses staff permission checks and hospital scoping', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();

    $this->actingAs($user)->get(route('staff.index'))->assertOk();
    $this->actingAs($user)->get(route('staff.create'))->assertOk();
    $this->actingAs($user)->get(route('staff.show', $staff))->assertOk();
    $this->actingAs($user)->get(route('staff.edit', $staff))->assertOk();
});
