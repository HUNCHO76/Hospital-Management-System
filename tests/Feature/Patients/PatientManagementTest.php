<?php

use App\Models\Patient;
use App\Models\PatientContact;

test('an authorized user can view the patients list', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/index'));
});

test('an authorized user can register a patient', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Amina',
        'last_name' => 'Hassan',
        'gender' => 'female',
        'date_of_birth' => '1990-05-10',
        'status' => 'active',
    ]);

    $patient = Patient::query()->where('first_name', 'Amina')->where('last_name', 'Hassan')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->patient_number)->toMatch('/^PT-\d{4}-\d{6}$/');

    $response->assertRedirect(route('patients.show', $patient));
    $response->assertSessionHas('success');
});

test('patient numbers are unique across multiple registrations', function () {
    $user = userWithRole('super-admin');

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('patients.store'), [
            'first_name' => "Patient{$i}",
            'last_name' => 'Test',
            'gender' => 'male',
            'status' => 'active',
        ]);
    }

    $numbers = Patient::query()->pluck('patient_number');

    expect($numbers->unique())->toHaveCount(3);
});

test('a patient can be registered with contacts', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Baraka',
        'last_name' => 'Mushi',
        'gender' => 'male',
        'status' => 'active',
        'contacts' => [
            ['name' => 'Neema Mushi', 'relationship' => 'spouse', 'phone' => '0711000000', 'is_primary' => true],
            ['name' => 'Juma Mushi', 'relationship' => 'sibling', 'phone' => '0722000000', 'is_primary' => false],
        ],
    ]);

    $response->assertSessionDoesntHaveErrors();

    $patient = Patient::query()->where('first_name', 'Baraka')->first();
    expect($patient->contacts()->count())->toBe(2)
        ->and($patient->contacts()->where('is_primary', true)->count())->toBe(1);
});

test('only one contact can be marked primary', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Two',
        'last_name' => 'Primaries',
        'gender' => 'male',
        'status' => 'active',
        'contacts' => [
            ['name' => 'A', 'relationship' => 'spouse', 'phone' => '0711000000', 'is_primary' => true],
            ['name' => 'B', 'relationship' => 'sibling', 'phone' => '0722000000', 'is_primary' => true],
        ],
    ]);

    $response->assertSessionHasErrors('contacts');
});

test('missing required patient fields are rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), []);

    $response->assertSessionHasErrors(['first_name', 'last_name', 'gender', 'status']);
});

test('an invalid gender is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'other',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('gender');
});

test('an invalid email is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'male',
        'status' => 'active',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('a future date of birth is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'male',
        'status' => 'active',
        'date_of_birth' => now()->addDay()->toDateString(),
    ]);

    $response->assertSessionHasErrors('date_of_birth');
});

test('an incomplete contact is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('patients.store'), [
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'male',
        'status' => 'active',
        'contacts' => [
            ['name' => 'No Phone Or Relationship'],
        ],
    ]);

    $response->assertSessionHasErrors(['contacts.0.relationship', 'contacts.0.phone']);
});

test('an authorized user can update a patient', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create(['occupation' => 'Farmer']);

    $response = $this->actingAs($user)->put(route('patients.update', $patient), [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'gender' => $patient->gender,
        'status' => 'active',
        'occupation' => 'Teacher',
    ]);

    $response->assertRedirect(route('patients.show', $patient));
    expect($patient->fresh()->occupation)->toBe('Teacher');
});

test('the patient number cannot be changed through an update', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $originalNumber = $patient->patient_number;

    $this->actingAs($user)->put(route('patients.update', $patient), [
        'patient_number' => 'PT-2099-999999',
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'gender' => $patient->gender,
        'status' => 'active',
    ]);

    expect($patient->fresh()->patient_number)->toBe($originalNumber);
});

test('updating a patient replaces their contacts safely', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $keep = PatientContact::factory()->create(['patient_id' => $patient->id, 'name' => 'Keep Me']);
    $remove = PatientContact::factory()->create(['patient_id' => $patient->id, 'name' => 'Remove Me']);

    $this->actingAs($user)->put(route('patients.update', $patient), [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'gender' => $patient->gender,
        'status' => 'active',
        'contacts' => [
            ['id' => $keep->id, 'name' => 'Keep Me Updated', 'relationship' => $keep->relationship, 'phone' => $keep->phone, 'is_primary' => false],
            ['name' => 'New Contact', 'relationship' => 'friend', 'phone' => '0733000000', 'is_primary' => false],
        ],
    ]);

    $patient->refresh();
    expect($patient->contacts()->count())->toBe(2)
        ->and($patient->contacts()->find($keep->id)->name)->toBe('Keep Me Updated')
        ->and($patient->contacts()->find($remove->id))->toBeNull()
        ->and($patient->contacts()->where('name', 'New Contact')->exists())->toBeTrue();
});

test('a contact id belonging to another patient is rejected', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $otherContact = PatientContact::factory()->create(['patient_id' => $otherPatient->id]);

    $response = $this->actingAs($user)->put(route('patients.update', $patient), [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'gender' => $patient->gender,
        'status' => 'active',
        'contacts' => [
            ['id' => $otherContact->id, 'name' => 'Hijack', 'relationship' => 'friend', 'phone' => '0700000000', 'is_primary' => false],
        ],
    ]);

    $response->assertSessionHasErrors('contacts.0.id');
});

test('an authorized user can view a single patient', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $this->actingAs($user)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/show'));
});

test('a patient can be safely deactivated', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user)->delete(route('patients.destroy', $patient));

    $response->assertRedirect(route('patients.index'));
    $this->assertSoftDeleted($patient);
    expect(Patient::withTrashed()->find($patient->id)->status)->toBe('inactive');
});

test('deactivating a patient does not permanently destroy the record', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $this->actingAs($user)->delete(route('patients.destroy', $patient));

    $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    expect(Patient::withTrashed()->find($patient->id))->not->toBeNull();
});

test('patients can be searched by name, patient number and national id', function () {
    $user = userWithRole('super-admin');
    $findable = Patient::factory()->create(['first_name' => 'Unique', 'last_name' => 'Findable', 'national_id' => 'NID-999']);
    Patient::factory()->create(['first_name' => 'Other', 'last_name' => 'Person']);

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => 'Findable']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => $findable->patient_number]))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    $this->actingAs($user)
        ->get(route('patients.index', ['search' => 'NID-999']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));
});

test('patients can be filtered by gender and status', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->create(['gender' => 'male', 'status' => 'active']);
    Patient::factory()->create(['gender' => 'female', 'status' => 'active']);
    Patient::factory()->create(['gender' => 'male', 'status' => 'inactive']);

    $this->actingAs($user)
        ->get(route('patients.index', ['gender' => 'female']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));

    $this->actingAs($user)
        ->get(route('patients.index', ['gender' => 'male', 'status' => 'active']))
        ->assertInertia(fn ($page) => $page->where('patients.total', 1));
});

test('patients list is paginated', function () {
    $user = userWithRole('super-admin');
    Patient::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('patients.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('patients.per_page', 15)
        ->has('patients.data', 15)
    );
});
