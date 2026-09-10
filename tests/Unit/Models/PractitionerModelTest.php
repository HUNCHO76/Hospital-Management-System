<?php

use App\Models\Practitioner;
use App\Models\Staff;

test('a created practitioner receives a formatted business number', function () {
    $practitioner = Practitioner::factory()->create();

    expect($practitioner->practitioner_number)->toMatch('/^PRC-\d{4}-\d{6}$/');
});

test('a practitioner belongs to a staff member', function () {
    $staff = Staff::factory()->create();
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);

    expect($practitioner->staff)->toBeInstanceOf(Staff::class)
        ->and($practitioner->staff->id)->toBe($staff->id);
});
