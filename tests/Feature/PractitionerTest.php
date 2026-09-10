<?php

use App\Models\Practitioner;
use App\Models\Staff;
use Illuminate\Database\QueryException;

test('a staff member can have one practitioner profile', function () {
    $staff = Staff::factory()->create(['job_title' => 'Doctor']);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $staff->id,
        'practitioner_type' => 'doctor',
    ]);

    expect($staff->fresh()->practitioner)->toBeInstanceOf(Practitioner::class)
        ->and($staff->fresh()->practitioner->id)->toBe($practitioner->id);
});

test('a practitioner must belong to a staff member', function () {
    $practitioner = Practitioner::factory()->create();

    expect($practitioner->staff)->toBeInstanceOf(Staff::class);
});

test('a receptionist can exist without a practitioner profile', function () {
    $staff = Staff::factory()->create(['job_title' => 'Receptionist']);

    expect($staff->practitioner)->toBeNull()
        ->and($staff->isPractitioner())->toBeFalse();
});

test('an accountant can exist without a practitioner profile', function () {
    $staff = Staff::factory()->create(['job_title' => 'Accountant']);

    expect($staff->practitioner)->toBeNull()
        ->and($staff->isPractitioner())->toBeFalse();
});

test('a doctor can have a practitioner profile', function () {
    $staff = Staff::factory()->create(['job_title' => 'Doctor']);
    $staff->practitioner()->create(['practitioner_type' => 'doctor', 'status' => 'active']);

    expect($staff->fresh()->isPractitioner())->toBeTrue();
});

test('a practitioner staff_id cannot be duplicated', function () {
    $staff = Staff::factory()->create();
    Practitioner::factory()->create(['staff_id' => $staff->id]);

    expect(fn () => Practitioner::factory()->create(['staff_id' => $staff->id]))
        ->toThrow(QueryException::class);
});
