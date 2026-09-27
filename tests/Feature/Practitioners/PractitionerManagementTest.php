<?php

use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;

test('an authorized user can view the practitioners list', function () {
    $user = userWithRole('super-admin');
    Practitioner::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('practitioners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('practitioners/index'));
});

test('an authorized user can create a practitioner profile for an eligible staff member', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['job_title' => 'Doctor']);

    $response = $this->actingAs($user)->post(route('practitioners.store'), [
        'staff_id' => $staff->id,
        'practitioner_type' => 'doctor',
        'professional_registration_number' => 'REG-1',
        'specialization' => 'Cardiology',
        'license_number' => 'LIC-1',
        'license_expiry_date' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $response->assertRedirect(route('practitioners.index'));
    $response->assertSessionHas('success');

    $practitioner = Practitioner::query()->where('staff_id', $staff->id)->first();
    expect($practitioner)->not->toBeNull()
        ->and($practitioner->practitioner_number)->toMatch('/^PRC-\d{4}-\d{6}$/');
});

test('a receptionist is not automatically given a practitioner profile', function () {
    $staff = Staff::factory()->create(['job_title' => 'Receptionist']);

    expect($staff->practitioner)->toBeNull();
});

test('an authorized user can update a practitioner', function () {
    $user = userWithRole('super-admin');
    $practitioner = Practitioner::factory()->create(['specialization' => 'Old']);

    $response = $this->actingAs($user)->put(route('practitioners.update', $practitioner), [
        'practitioner_type' => $practitioner->practitioner_type,
        'professional_registration_number' => $practitioner->professional_registration_number,
        'specialization' => 'New',
        'license_number' => $practitioner->license_number,
        'license_expiry_date' => $practitioner->license_expiry_date,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('practitioners.index'));
    expect($practitioner->fresh()->specialization)->toBe('New');
});

test('an authorized user can set a practitioner-specific consultation service when creating a practitioner', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['job_title' => 'Doctor']);
    $service = serviceFor(['category' => 'consultation']);

    $response = $this->actingAs($user)->post(route('practitioners.store'), [
        'staff_id' => $staff->id,
        'practitioner_type' => 'doctor',
        'license_expiry_date' => now()->addYear()->toDateString(),
        'status' => 'active',
        'consultation_service_id' => $service->id,
    ]);

    $response->assertRedirect(route('practitioners.index'));

    $practitioner = Practitioner::query()->where('staff_id', $staff->id)->first();
    expect($practitioner->consultation_service_id)->toBe($service->id);
});

test('a practitioner-specific consultation service can be updated to and cleared from a practitioner', function () {
    $user = userWithRole('super-admin');
    $service = serviceFor(['category' => 'consultation']);
    $practitioner = Practitioner::factory()->create();

    $this->actingAs($user)->put(route('practitioners.update', $practitioner), [
        'practitioner_type' => $practitioner->practitioner_type,
        'license_number' => $practitioner->license_number,
        'license_expiry_date' => $practitioner->license_expiry_date,
        'status' => 'active',
        'consultation_service_id' => $service->id,
    ]);

    expect($practitioner->fresh()->consultation_service_id)->toBe($service->id);

    $this->actingAs($user)->put(route('practitioners.update', $practitioner), [
        'practitioner_type' => $practitioner->practitioner_type,
        'license_number' => $practitioner->license_number,
        'license_expiry_date' => $practitioner->license_expiry_date,
        'status' => 'active',
        'consultation_service_id' => null,
    ]);

    expect($practitioner->fresh()->consultation_service_id)->toBeNull();
});

test('an invalid consultation service id is rejected', function () {
    $user = userWithRole('super-admin');
    $practitioner = Practitioner::factory()->create();

    $response = $this->actingAs($user)->put(route('practitioners.update', $practitioner), [
        'practitioner_type' => $practitioner->practitioner_type,
        'license_number' => $practitioner->license_number,
        'license_expiry_date' => $practitioner->license_expiry_date,
        'status' => 'active',
        'consultation_service_id' => 999999,
    ]);

    $response->assertSessionHasErrors('consultation_service_id');
});

test('an authorized user can view a single practitioner', function () {
    $user = userWithRole('super-admin');
    $practitioner = Practitioner::factory()->create();

    $this->actingAs($user)
        ->get(route('practitioners.show', $practitioner))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('practitioners/show'));
});

test('an authorized user can delete a practitioner without affecting the staff record', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();
    $practitioner = $staff->practitioner()->create(['practitioner_type' => 'doctor', 'status' => 'active']);

    $response = $this->actingAs($user)->delete(route('practitioners.destroy', $practitioner));

    $response->assertRedirect(route('practitioners.index'));
    $this->assertDatabaseMissing('practitioners', ['id' => $practitioner->id]);
    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'deleted_at' => null]);
});

test('a staff member cannot receive a second practitioner profile', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();
    Practitioner::factory()->create(['staff_id' => $staff->id]);

    $response = $this->actingAs($user)->post(route('practitioners.store'), [
        'staff_id' => $staff->id,
        'practitioner_type' => 'nurse',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('staff_id');
});

test('an invalid practitioner type is rejected', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create();

    $response = $this->actingAs($user)->post(route('practitioners.store'), [
        'staff_id' => $staff->id,
        'practitioner_type' => 'wizard',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('practitioner_type');
});

test('missing required practitioner fields are rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('practitioners.store'), []);

    $response->assertSessionHasErrors(['staff_id', 'practitioner_type', 'status']);
});

test('practitioners can be searched by staff name', function () {
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['first_name' => 'Findable', 'last_name' => 'Practitioner']);
    Practitioner::factory()->create(['staff_id' => $staff->id]);
    Practitioner::factory()->create();

    $response = $this->actingAs($user)->get(route('practitioners.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page->where('practitioners.total', 1));
});

test('practitioners can be filtered by hospital and type', function () {
    $user = userWithRole('super-admin');
    $hospitalA = Hospital::factory()->create();
    $staffA = Staff::factory()->create(['hospital_id' => $hospitalA->id]);
    Practitioner::factory()->create(['staff_id' => $staffA->id, 'practitioner_type' => 'doctor']);
    Practitioner::factory()->create(['practitioner_type' => 'nurse']);

    $this->actingAs($user)
        ->get(route('practitioners.index', ['hospital_id' => $hospitalA->id]))
        ->assertInertia(fn ($page) => $page->where('practitioners.total', 1));

    $this->actingAs($user)
        ->get(route('practitioners.index', ['practitioner_type' => 'nurse']))
        ->assertInertia(fn ($page) => $page->where('practitioners.total', 1));
});

test('practitioners list is paginated', function () {
    $user = userWithRole('super-admin');
    Practitioner::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('practitioners.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('practitioners.per_page', 15)
        ->has('practitioners.data', 15)
    );
});
