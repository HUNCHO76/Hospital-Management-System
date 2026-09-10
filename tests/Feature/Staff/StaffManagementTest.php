<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('user has one staff record', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->create(['user_id' => $user->id]);

    expect($user->staff)->toBeInstanceOf(Staff::class)
        ->and($user->staff->id)->toBe($staff->id);
});

test('an authorized user can view the staff list', function () {
    $user = userWithRole('super-admin');
    Staff::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('staff.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('staff/index'));
});

test('an authorized user can create a staff member without an account', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);

    $response = $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospital->id,
        'department_id' => $department->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'gender' => 'female',
        'job_title' => 'Receptionist',
        'employment_type' => 'full_time',
        'status' => 'active',
        'create_account' => false,
    ]);

    $response->assertRedirect(route('staff.index'));
    $response->assertSessionHas('success');

    $staff = Staff::query()->where('first_name', 'Jane')->where('last_name', 'Doe')->first();
    expect($staff)->not->toBeNull()
        ->and($staff->user_id)->toBeNull()
        ->and($staff->staff_number)->toMatch('/^STF-\d{4}-\d{6}$/');
});

test('creating a staff member with an account creates a linked user with the chosen role', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);

    $response = $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospital->id,
        'department_id' => $department->id,
        'first_name' => 'John',
        'last_name' => 'Smith',
        'gender' => 'male',
        'job_title' => 'Doctor',
        'employment_type' => 'full_time',
        'status' => 'active',
        'create_account' => true,
        'email' => 'john.smith@example.test',
        'password' => 'Password123!',
        'role' => 'doctor',
    ]);

    $response->assertRedirect(route('staff.index'));

    $staff = Staff::query()->where('first_name', 'John')->first();
    expect($staff->user_id)->not->toBeNull();

    $createdUser = $staff->user;
    expect($createdUser->email)->toBe('john.smith@example.test')
        ->and($createdUser->hasRole('doctor'))->toBeTrue()
        ->and($createdUser->password)->not->toBe('Password123!');
});

test('a staff member without create_account does not receive a user account', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospital->id,
        'first_name' => 'Grace',
        'last_name' => 'Temba',
        'gender' => 'female',
        'job_title' => 'IT Officer',
        'employment_type' => 'full_time',
        'status' => 'active',
        'create_account' => false,
    ]);

    $staff = Staff::query()->where('first_name', 'Grace')->first();
    expect($staff->user_id)->toBeNull()
        ->and($staff->practitioner)->toBeNull();
});

test('an authorized user can update a staff member', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id, 'department_id' => $department->id, 'job_title' => 'Old Title']);

    $response = $this->actingAs($user)->put(route('staff.update', $staff), [
        'hospital_id' => $staff->hospital_id,
        'department_id' => $staff->department_id,
        'first_name' => $staff->first_name,
        'last_name' => $staff->last_name,
        'gender' => $staff->gender,
        'job_title' => 'New Title',
        'employment_type' => $staff->employment_type,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('staff.index'));
    expect($staff->fresh()->job_title)->toBe('New Title');
});

test('an authorized user can delete a staff member', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();

    $response = $this->actingAs($user)->delete(route('staff.destroy', $staff));

    $response->assertRedirect(route('staff.index'));
    $this->assertSoftDeleted($staff);
});

test('a staff member with a practitioner profile cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();
    $staff->practitioner()->create(['practitioner_type' => 'doctor', 'status' => 'active']);

    $this->actingAs($user)->delete(route('staff.destroy', $staff));

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'deleted_at' => null]);
});

test('missing required staff fields are rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('staff.store'), []);

    $response->assertSessionHasErrors(['hospital_id', 'first_name', 'last_name', 'gender', 'job_title', 'employment_type', 'status']);
});

test('a department from a different hospital is rejected', function () {
    $user = userWithRole('super-admin');
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $departmentB = Department::factory()->create(['hospital_id' => $hospitalB->id]);

    $response = $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospitalA->id,
        'department_id' => $departmentB->id,
        'first_name' => 'Cross',
        'last_name' => 'Hospital',
        'gender' => 'male',
        'job_title' => 'Nurse',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('department_id');
    $this->assertDatabaseMissing('staff', ['first_name' => 'Cross', 'last_name' => 'Hospital']);
});

test('creating an account with an email already in use is rejected', function () {
    $user = userWithRole('super-admin');
    $existing = User::factory()->create(['email' => 'taken@example.test']);
    $hospital = Hospital::factory()->create();

    $response = $this->actingAs($user)->post(route('staff.store'), [
        'hospital_id' => $hospital->id,
        'first_name' => 'Dup',
        'last_name' => 'Licate',
        'gender' => 'male',
        'job_title' => 'Doctor',
        'employment_type' => 'full_time',
        'status' => 'active',
        'create_account' => true,
        'email' => 'taken@example.test',
        'password' => 'Password123!',
        'role' => 'doctor',
    ]);

    $response->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

test('staff can be searched by name and staff number', function () {
    $user = userWithRole('super-admin');
    $findable = Staff::factory()->create(['first_name' => 'Unique', 'last_name' => 'Findable']);
    Staff::factory()->create(['first_name' => 'Other', 'last_name' => 'Person']);

    $response = $this->actingAs($user)->get(route('staff.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page->where('staff.total', 1));

    $response = $this->actingAs($user)->get(route('staff.index', ['search' => $findable->staff_number]));
    $response->assertInertia(fn ($page) => $page->where('staff.total', 1));
});

test('staff can be filtered by hospital, status and practitioner presence', function () {
    $user = userWithRole('super-admin');
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $doctor = Staff::factory()->create(['hospital_id' => $hospitalA->id, 'status' => 'active']);
    $doctor->practitioner()->create(['practitioner_type' => 'doctor', 'status' => 'active']);
    Staff::factory()->create(['hospital_id' => $hospitalA->id, 'status' => 'inactive']);
    Staff::factory()->create(['hospital_id' => $hospitalB->id, 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('staff.index', ['hospital_id' => $hospitalA->id]))
        ->assertInertia(fn ($page) => $page->where('staff.total', 2));

    $this->actingAs($user)
        ->get(route('staff.index', ['hospital_id' => $hospitalA->id, 'status' => 'active']))
        ->assertInertia(fn ($page) => $page->where('staff.total', 1));

    $this->actingAs($user)
        ->get(route('staff.index', ['has_practitioner' => '1']))
        ->assertInertia(fn ($page) => $page->where('staff.total', 1));
});

test('staff list is paginated', function () {
    $user = userWithRole('super-admin');
    Staff::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('staff.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('staff.per_page', 15)
        ->has('staff.data', 15)
    );
});
