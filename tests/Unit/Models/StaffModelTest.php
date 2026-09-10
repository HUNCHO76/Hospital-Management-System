<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;

test('a created staff member receives a formatted business number', function () {
    $staff = Staff::factory()->create();

    expect($staff->staff_number)->toMatch('/^STF-\d{4}-\d{6}$/');
});

test('a staff member belongs to a hospital and department', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id, 'department_id' => $department->id]);

    expect($staff->hospital)->toBeInstanceOf(Hospital::class)
        ->and($staff->hospital->id)->toBe($hospital->id)
        ->and($staff->department)->toBeInstanceOf(Department::class)
        ->and($staff->department->id)->toBe($department->id);
});

test('a staff member may belong to a user account', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->create(['user_id' => $user->id]);

    expect($staff->user)->toBeInstanceOf(User::class)
        ->and($staff->user->id)->toBe($user->id);
});

test('a staff member optionally has one practitioner profile', function () {
    $staff = Staff::factory()->create();
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);

    expect($staff->practitioner)->toBeInstanceOf(Practitioner::class)
        ->and($staff->practitioner->id)->toBe($practitioner->id);
});

test('a staff member is soft deleted rather than removed', function () {
    $staff = Staff::factory()->create();

    $staff->delete();

    expect(Staff::query()->find($staff->id))->toBeNull()
        ->and(Staff::withTrashed()->find($staff->id))->not->toBeNull();
});
