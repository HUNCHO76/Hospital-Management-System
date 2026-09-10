<?php

use App\Models\Hospital;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('a guest is redirected to login for every hospital route', function () {
    $hospital = Hospital::factory()->create();

    $this->get(route('hospitals.index'))->assertRedirect(route('login'));
    $this->get(route('hospitals.create'))->assertRedirect(route('login'));
    $this->get(route('hospitals.show', $hospital))->assertRedirect(route('login'));
    $this->get(route('hospitals.edit', $hospital))->assertRedirect(route('login'));
});

test('a user without hospitals.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('hospitals.index'))->assertForbidden();
});

test('a user without hospitals.create receives a 403 on the create page', function () {
    $user = userWithRole('doctor');

    $this->actingAs($user)->get(route('hospitals.create'))->assertForbidden();
    $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'Should Not Exist',
        'code' => 'NOPE',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('hospitals', ['code' => 'NOPE']);
});

test('a user without hospitals.update receives a 403', function () {
    $user = userWithRole('doctor');
    $hospital = Hospital::factory()->create(['name' => 'Untouched']);

    $this->actingAs($user)
        ->put(route('hospitals.update', $hospital), ['name' => 'Hacked', 'code' => $hospital->code, 'status' => 'active'])
        ->assertForbidden();

    expect($hospital->fresh()->name)->toBe('Untouched');
});

test('a user without hospitals.delete receives a 403', function () {
    $user = userWithRole('doctor');
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->delete(route('hospitals.destroy', $hospital))->assertForbidden();

    $this->assertDatabaseHas('hospitals', ['id' => $hospital->id, 'deleted_at' => null]);
});

test('a user cannot bypass hospital authorization by visiting the url directly', function () {
    $user = User::factory()->create();
    Role::findOrCreate('receptionist');
    $user->assignRole('receptionist');

    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->get(route('hospitals.edit', $hospital))->assertForbidden();
});

test('a super-admin bypasses hospital permission checks entirely', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->get(route('hospitals.index'))->assertOk();
    $this->actingAs($user)->get(route('hospitals.create'))->assertOk();
    $this->actingAs($user)->get(route('hospitals.edit', $hospital))->assertOk();
});
