<?php

use App\Models\LaboratoryTestCategory;

test('a duplicate test code is rejected', function () {
    $admin = userWithRole('hospital-admin');
    activeLaboratoryTest(['code' => 'DUP']);

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'DUP',
        'name' => 'Duplicate Attempt',
    ])->assertSessionHasErrors('code');

    $this->assertDatabaseCount('laboratory_tests', 1);
});

test('a missing test name is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-tests.store'), ['code' => 'NONAME'])->assertSessionHasErrors('name');
});

test('an invalid category is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'BADCAT',
        'name' => 'Bad Category Test',
        'category_id' => 999999,
    ])->assertSessionHasErrors('category_id');
});

test('an invalid result type is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'BADRESULT',
        'name' => 'Bad Result Type',
        'result_type' => 'not_a_real_type',
    ])->assertSessionHasErrors('result_type');
});

test('a negative turnaround time is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'NEGTAT',
        'name' => 'Negative TAT',
        'turnaround_time_minutes' => -30,
    ])->assertSessionHasErrors('turnaround_time_minutes');
});

test('an effective_to before effective_from is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-tests.store'), [
        'code' => 'BADDATES',
        'name' => 'Bad Effective Dates',
        'effective_from' => '2026-06-01',
        'effective_to' => '2026-01-01',
    ])->assertSessionHasErrors('effective_to');
});

test('a duplicate category code is rejected', function () {
    $admin = userWithRole('hospital-admin');
    LaboratoryTestCategory::factory()->create(['code' => 'DUPCAT']);

    $this->actingAs($admin)->post(route('laboratory-categories.store'), [
        'name' => 'Duplicate Category',
        'code' => 'DUPCAT',
    ])->assertSessionHasErrors('code');

    $this->assertDatabaseCount('laboratory_test_categories', 1);
});

test('a missing category name is rejected', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->post(route('laboratory-categories.store'), ['code' => 'NONAME'])->assertSessionHasErrors('name');
});
