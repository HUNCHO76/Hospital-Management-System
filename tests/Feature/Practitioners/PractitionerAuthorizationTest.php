<?php

use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every practitioner route', function () {
    $practitioner = Practitioner::factory()->create();

    $this->get(route('practitioners.index'))->assertRedirect(route('login'));
    $this->get(route('practitioners.create'))->assertRedirect(route('login'));
    $this->get(route('practitioners.show', $practitioner))->assertRedirect(route('login'));
    $this->get(route('practitioners.edit', $practitioner))->assertRedirect(route('login'));
});

test('a user without practitioners.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('practitioners.index'))->assertForbidden();
});

test('a user without practitioners.create receives a 403', function () {
    $user = userWithRole('receptionist');
    $staff = Staff::factory()->create();

    $this->actingAs($user)->get(route('practitioners.create'))->assertForbidden();
    $this->actingAs($user)->post(route('practitioners.store'), [
        'staff_id' => $staff->id,
        'practitioner_type' => 'doctor',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('practitioners', ['staff_id' => $staff->id]);
});

test('a user without practitioners.update receives a 403', function () {
    $user = userWithRole('receptionist');
    $practitioner = Practitioner::factory()->create(['specialization' => 'Untouched']);

    $this->actingAs($user)
        ->put(route('practitioners.update', $practitioner), [
            'practitioner_type' => $practitioner->practitioner_type,
            'specialization' => 'Hacked',
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($practitioner->fresh()->specialization)->toBe('Untouched');
});

test('a user without practitioners.delete receives a 403', function () {
    $user = userWithRole('receptionist');
    $practitioner = Practitioner::factory()->create();

    $this->actingAs($user)->delete(route('practitioners.destroy', $practitioner))->assertForbidden();

    $this->assertDatabaseHas('practitioners', ['id' => $practitioner->id]);
});

test('a hospital-admin cannot manage another hospital practitioner', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);
    $practitionerB = Practitioner::factory()->create(['staff_id' => $staffB->id]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('practitioners.show', $practitionerB))->assertForbidden();
    $this->actingAs($adminUser)
        ->post(route('practitioners.store'), [
            'staff_id' => $staffB->id,
            'practitioner_type' => 'nurse',
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('a super-admin bypasses practitioner permission checks and hospital scoping', function () {
    $user = userWithRole('super-admin');
    $practitioner = Practitioner::factory()->create();

    $this->actingAs($user)->get(route('practitioners.index'))->assertOk();
    $this->actingAs($user)->get(route('practitioners.create'))->assertOk();
    $this->actingAs($user)->get(route('practitioners.show', $practitioner))->assertOk();
    $this->actingAs($user)->get(route('practitioners.edit', $practitioner))->assertOk();
});
