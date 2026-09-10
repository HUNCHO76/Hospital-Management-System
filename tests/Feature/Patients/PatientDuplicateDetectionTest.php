<?php

use App\Models\Patient;

test('a matching national id is a confirmed duplicate and blocks registration', function () {
    $user = userWithRole('super-admin');
    $existing = Patient::factory()->create(['national_id' => 'NID-12345']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Completely',
        'last_name' => 'Different',
        'gender' => 'male',
        'status' => 'active',
        'national_id' => 'NID-12345',
    ]);

    $response->assertRedirect(route('patients.create'));
    $response->assertSessionHas('confirmedDuplicate', fn ($ref) => $ref['id'] === $existing->id);
    expect(Patient::query()->where('first_name', 'Completely')->exists())->toBeFalse();
});

test('a matching passport number is a confirmed duplicate and blocks registration', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['passport_number' => 'P-AA0001']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Passport',
        'last_name' => 'Twin',
        'gender' => 'female',
        'status' => 'active',
        'passport_number' => 'P-AA0001',
    ]);

    $response->assertSessionHas('confirmedDuplicate');
    expect(Patient::query()->where('first_name', 'Passport')->exists())->toBeFalse();
});

test('a confirmed duplicate cannot be overridden even with confirm_duplicate', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['national_id' => 'NID-NOOVERRIDE']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Should',
        'last_name' => 'Block',
        'gender' => 'male',
        'status' => 'active',
        'national_id' => 'NID-NOOVERRIDE',
        'confirm_duplicate' => true,
    ]);

    $response->assertSessionHas('confirmedDuplicate');
    expect(Patient::query()->where('first_name', 'Should')->exists())->toBeFalse();
});

test('a matching phone number is only a possible duplicate warning', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['phone' => '0712345678']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Another',
        'last_name' => 'Person',
        'gender' => 'female',
        'status' => 'active',
        'phone' => '0712345678',
    ]);

    $response->assertSessionHas('possibleDuplicates');
    expect(Patient::query()->where('first_name', 'Another')->exists())->toBeFalse();
});

test('a matching name and date of birth is only a possible duplicate warning', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'date_of_birth' => '1998-04-10']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '1998-04-10',
        'gender' => 'male',
        'status' => 'active',
    ]);

    $response->assertSessionHas('possibleDuplicates');
    expect(Patient::query()->whereDate('date_of_birth', '1998-04-10')->count())->toBe(1);
});

test('a matching name without a matching date of birth is not flagged as a duplicate', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'date_of_birth' => '1998-04-10']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'date_of_birth' => '2001-01-01',
        'gender' => 'male',
        'status' => 'active',
    ]);

    $response->assertSessionMissing('possibleDuplicates');
    $response->assertSessionMissing('confirmedDuplicate');
    expect(Patient::query()->whereDate('date_of_birth', '2001-01-01')->exists())->toBeTrue();
});

test('legitimate patients that merely share a name can still be registered', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'Grace', 'last_name' => 'Mwangi', 'date_of_birth' => '1985-02-20']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Grace',
        'last_name' => 'Mwangi',
        'gender' => 'female',
        'status' => 'active',
    ]);

    $response->assertSessionMissing('possibleDuplicates');
    expect(Patient::query()->where('first_name', 'Grace')->where('last_name', 'Mwangi')->count())->toBe(2);
});

test('confirming past a possible duplicate warning creates the patient anyway', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['phone' => '0700111222']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Confirmed',
        'last_name' => 'Possible',
        'gender' => 'male',
        'status' => 'active',
        'phone' => '0700111222',
        'confirm_duplicate' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Patient::query()->where('first_name', 'Confirmed')->exists())->toBeTrue();
});

test('no duplicate check flags anything when no identifying information is provided', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['first_name' => 'Anyone', 'last_name' => 'Atall']);

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'gender' => 'male',
        'status' => 'active',
    ]);

    $response->assertSessionMissing('possibleDuplicates');
    $response->assertSessionMissing('confirmedDuplicate');
    expect(Patient::query()->where('first_name', 'Someone')->exists())->toBeTrue();
});
