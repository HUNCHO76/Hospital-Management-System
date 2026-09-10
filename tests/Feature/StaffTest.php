<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('staff belongs to a hospital', function () {
    $hospital = Hospital::factory()->create();
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);

    expect($staff->hospital)->toBeInstanceOf(Hospital::class)
        ->and($staff->hospital->id)->toBe($hospital->id);
});

test('staff belongs to a department', function () {
    $department = Department::factory()->create();
    $staff = Staff::factory()->create(['department_id' => $department->id]);

    expect($staff->department)->toBeInstanceOf(Department::class)
        ->and($staff->department->id)->toBe($department->id);
});

test('staff can optionally have a user account', function () {
    $staffWithUser = Staff::factory()->create(['user_id' => User::factory()]);
    $staffWithoutUser = Staff::factory()->create(['user_id' => null]);

    expect($staffWithUser->user)->toBeInstanceOf(User::class)
        ->and($staffWithoutUser->user)->toBeNull();
});

test('a staff number is generated automatically', function () {
    $staff = Staff::factory()->create();

    expect($staff->staff_number)->toMatch('/^STF-\d{4}-\d{6}$/');
});
