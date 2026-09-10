<?php

use App\Models\Patient;

test('an exact patient number returns only that patient', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create();
    Patient::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('patients.index', ['patient_number' => $target->patient_number]));

    $response->assertInertia(fn ($page) => $page
        ->where('patients.total', 1)
        ->where('patients.data.0.id', $target->id));
});

test('an exact national id filter returns only that patient', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['national_id' => 'NID-EXACT-1']);
    Patient::factory()->create(['national_id' => 'NID-EXACT-2']);

    $response = $this->actingAs($user)->get(route('patients.index', ['national_id' => 'NID-EXACT-1']));

    $response->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('an exact passport number filter returns only that patient', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['passport_number' => 'P-EXACT-1']);
    Patient::factory()->create(['passport_number' => 'P-EXACT-2']);

    $response = $this->actingAs($user)->get(route('patients.index', ['passport_number' => 'P-EXACT-1']));

    $response->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('a phone filter tolerates common formatting differences', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['phone' => '0712345678']);

    foreach (['0712345678', '+255712345678', '255712345678', '712345678'] as $variant) {
        $this->actingAs($user)
            ->get(route('patients.index', ['phone' => $variant]))
            ->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
    }
});

test('a date of birth filter returns only patients born on that date', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['date_of_birth' => '1998-04-15']);
    Patient::factory()->create(['date_of_birth' => '1998-04-16']);

    $response = $this->actingAs($user)->get(route('patients.index', ['date_of_birth' => '1998-04-15']));

    $response->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('a partial name search is case-insensitive and matches multiple tokens', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['first_name' => 'John', 'middle_name' => 'Michael', 'last_name' => 'Smith']);
    Patient::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => 'john']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => 'John Smith']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('name search combined with date of birth narrows to the correct patient', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'date_of_birth' => '1990-01-01']);
    $target = Patient::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'date_of_birth' => '1985-06-15']);

    $response = $this->actingAs($user)->get(route('patients.index', [
        'search' => 'John Doe',
        'date_of_birth' => '1985-06-15',
    ]));

    $response->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('national id combined with date of birth narrows results', function () {
    $user = userWithRole('super-admin');
    $target = Patient::factory()->create(['national_id' => 'NID-COMBO', 'date_of_birth' => '1970-03-03']);

    $response = $this->actingAs($user)->get(route('patients.index', [
        'national_id' => 'NID-COMBO',
        'date_of_birth' => '1970-03-03',
    ]));

    $response->assertInertia(fn ($page) => $page->where('patients.total', 1)->where('patients.data.0.id', $target->id));
});

test('a malformed date of birth is rejected without a server error', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)
        ->get(route('patients.index', ['date_of_birth' => 'not-a-date']))
        ->assertSessionHasErrors('date_of_birth');
});

test('an invalid gender filter is rejected', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)
        ->get(route('patients.index', ['gender' => 'unknown']))
        ->assertSessionHasErrors('gender');
});

test('an invalid status filter is rejected', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)
        ->get(route('patients.index', ['status' => 'archived']))
        ->assertSessionHasErrors('status');
});

test('a search shorter than the minimum length is rejected', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => 'a']))
        ->assertSessionHasErrors('search');
});

test('sql-injection-style search input is handled safely and returns no error', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create();

    $response = $this->actingAs($user)->get(route('patients.index', ['search' => "' OR 1=1 --"]));

    $response->assertOk();
});

test('xss-style search input is handled safely and never reflected unescaped', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->get(route('patients.index', ['search' => '<script>alert(1)</script>']));

    $response->assertOk();
    $response->assertDontSee('<script>alert(1)</script>', false);
});

test('search results mask the patient phone number', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'Masked', 'last_name' => 'Phone', 'phone' => '0712345678']);

    $response = $this->actingAs($user)->get(route('patients.index', ['search' => 'Masked Phone']));

    $response->assertInertia(fn ($page) => $page->where('patients.data.0.phone', '0712****78'));
});

test('results can be sorted by a whitelisted column in both directions', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'Aaron', 'last_name' => 'Zeta']);
    Patient::factory()->create(['first_name' => 'Zack', 'last_name' => 'Alpha']);

    $asc = $this->actingAs($user)->get(route('patients.index', ['sort' => 'last_name']));
    $asc->assertInertia(fn ($page) => $page->where('patients.data.0.last_name', 'Alpha'));

    $desc = $this->actingAs($user)->get(route('patients.index', ['sort' => '-last_name']));
    $desc->assertInertia(fn ($page) => $page->where('patients.data.0.last_name', 'Zeta'));
});

test('an unsupported sort column falls back to the default order without error', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('patients.index', ['sort' => 'email); DROP TABLE patients;--']))
        ->assertOk();

    expect(Patient::count())->toBe(2);
});

test('no filters returns a controlled paginated list rather than the entire table', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('patients.index'));

    $response->assertInertia(fn ($page) => $page->has('patients.data', 15)->where('patients.total', 20));
});
