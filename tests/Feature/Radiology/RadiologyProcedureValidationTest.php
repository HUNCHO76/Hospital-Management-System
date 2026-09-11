<?php

test('a duplicate procedure code is rejected', function () {
    $admin = userWithRole('hospital-admin');
    activeRadiologyProcedure(['code' => 'DUP-CODE']);

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'DUP-CODE',
        'name' => 'Duplicate Attempt',
    ])->assertSessionHasErrors('code');

    $this->assertDatabaseCount('radiology_procedures', 1);
});

test('a missing procedure name is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), ['code' => 'NONAME'])->assertSessionHasErrors('name');
});

test('an invalid modality is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADMOD',
        'name' => 'Bad Modality',
        'modality' => 'not_a_real_modality',
    ])->assertSessionHasErrors('modality');
});

test('an invalid body site is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADSITE',
        'name' => 'Bad Body Site',
        'body_sites' => ['not_a_real_site'],
    ])->assertSessionHasErrors('body_sites.0');
});

test('an invalid laterality is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADLAT',
        'name' => 'Bad Laterality',
        'laterality' => 'diagonal',
    ])->assertSessionHasErrors('laterality');
});

test('an invalid contrast requirement is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADCONTRAST',
        'name' => 'Bad Contrast',
        'contrast_requirement' => 'always',
    ])->assertSessionHasErrors('contrast_requirement');
});

test('an invalid preparation type is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADPREP',
        'name' => 'Bad Preparation',
        'preparation_type' => 'not_a_real_type',
    ])->assertSessionHasErrors('preparation_type');
});

test('a negative turnaround time is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'NEGTAT',
        'name' => 'Negative TAT',
        'turnaround_time_minutes' => -30,
    ])->assertSessionHasErrors('turnaround_time_minutes');
});

test('an invalid department is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('radiology-catalog.store'), [
        'code' => 'BADDEPT',
        'name' => 'Bad Department',
        'department_id' => 999999,
    ])->assertSessionHasErrors('department_id');
});
