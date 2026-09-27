<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('hospital belongs to many departments', function () {
    $hospital = Hospital::factory()->create();
    Department::factory()->count(3)->create(['hospital_id' => $hospital->id]);

    expect($hospital->departments)->toHaveCount(3)
        ->and($hospital->departments->first())->toBeInstanceOf(Department::class);
});

test('department belongs to a hospital', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);

    expect($department->hospital)->toBeInstanceOf(Hospital::class)
        ->and($department->hospital->id)->toBe($hospital->id);
});

test('an authorized user can view the hospitals list', function () {
    $user = userWithRole('super-admin');
    Hospital::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('hospitals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('hospitals/index'));
});

test('an authorized user can create a hospital', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'City General Hospital',
        'code' => 'CGH',
        'registration_number' => 'REG-100200',
        'phone' => '+255700111222',
        'email' => 'info@citygeneral.test',
        'address' => '10 City Road',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'ward' => 'Msasani',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('hospitals.index'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hospitals', ['code' => 'CGH', 'name' => 'City General Hospital']);
});

test('an authorized user can update a hospital', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('hospitals.update', $hospital), [
        'name' => 'New Name',
        'code' => $hospital->code,
        'registration_number' => $hospital->registration_number,
        'phone' => $hospital->phone,
        'email' => $hospital->email,
        'address' => $hospital->address,
        'region' => $hospital->region,
        'district' => $hospital->district,
        'ward' => $hospital->ward,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('hospitals.index'));
    expect($hospital->fresh()->name)->toBe('New Name');
});

test('an authorized user can delete a hospital', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();

    $response = $this->actingAs($user)->delete(route('hospitals.destroy', $hospital));

    $response->assertRedirect(route('hospitals.index'));
    $this->assertSoftDeleted($hospital);
});

test('a hospital with departments cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    Department::factory()->create(['hospital_id' => $hospital->id]);

    $this->actingAs($user)->delete(route('hospitals.destroy', $hospital));

    $this->assertDatabaseHas('hospitals', ['id' => $hospital->id, 'deleted_at' => null]);
});

test('a hospital with staff cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    Staff::factory()->create(['hospital_id' => $hospital->id]);

    $this->actingAs($user)->delete(route('hospitals.destroy', $hospital));

    $this->assertDatabaseHas('hospitals', ['id' => $hospital->id, 'deleted_at' => null]);
});

test('duplicate hospital code is rejected', function () {
    $user = userWithRole('super-admin');
    Hospital::factory()->create(['code' => 'DUPE']);

    $response = $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'Another Hospital',
        'code' => 'DUPE',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('code');
});

test('a hospital may keep its own code when updated', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create(['code' => 'KEEP']);

    $response = $this->actingAs($user)->put(route('hospitals.update', $hospital), [
        'name' => $hospital->name,
        'code' => 'KEEP',
        'status' => 'active',
    ]);

    $response->assertSessionHasNoErrors();
});

test('missing required hospital fields are rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('hospitals.store'), []);

    $response->assertSessionHasErrors(['name', 'code', 'status']);
});

test('an invalid hospital email is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'Test Hospital',
        'code' => 'TST',
        'status' => 'active',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('an invalid hospital status is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'Test Hospital',
        'code' => 'TST',
        'status' => 'archived',
    ]);

    $response->assertSessionHasErrors('status');
});

test('hospitals can be searched by name', function () {
    $user = userWithRole('super-admin');
    Hospital::factory()->create(['name' => 'Findable Hospital', 'code' => 'FIND']);
    Hospital::factory()->create(['name' => 'Other Hospital', 'code' => 'OTHR']);

    $response = $this->actingAs($user)->get(route('hospitals.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page
        ->where('hospitals.data.0.code', 'FIND')
        ->where('hospitals.total', 1)
    );
});

test('hospitals can be filtered by status', function () {
    $user = userWithRole('super-admin');
    Hospital::factory()->create(['status' => 'active']);
    Hospital::factory()->create(['status' => 'inactive']);

    $response = $this->actingAs($user)->get(route('hospitals.index', ['status' => 'inactive']));

    $response->assertInertia(fn ($page) => $page->where('hospitals.total', 1));
});

test('hospitals list is paginated', function () {
    $user = userWithRole('super-admin');
    Hospital::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('hospitals.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('hospitals.per_page', 15)
        ->has('hospitals.data', 15)
    );
});

test('a hospital-admin can only update their own hospital', function () {
    seedRolesAndPermissions();

    $myHospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();

    $user = User::factory()->create();
    $user->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $myHospital->id]);

    $this->actingAs($user)
        ->put(route('hospitals.update', $myHospital), [
            'name' => 'Updated Name',
            'code' => $myHospital->code,
            'status' => 'active',
        ])
        ->assertRedirect(route('hospitals.index'));

    $this->actingAs($user)
        ->put(route('hospitals.update', $otherHospital), [
            'name' => 'Hijacked Name',
            'code' => $otherHospital->code,
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($otherHospital->fresh()->name)->not->toBe('Hijacked Name');
});

test('an authorized user can set a hospital default consultation service', function () {
    $user = userWithRole('super-admin');
    $service = serviceFor(['category' => 'consultation']);

    $response = $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'City General Hospital',
        'code' => 'CGH2',
        'status' => 'active',
        'default_consultation_service_id' => $service->id,
    ]);

    $response->assertRedirect(route('hospitals.index'));
    $this->assertDatabaseHas('hospitals', ['code' => 'CGH2', 'default_consultation_service_id' => $service->id]);
});

test('a hospital default consultation service can be cleared back to null', function () {
    $user = userWithRole('super-admin');
    $service = serviceFor(['category' => 'consultation']);
    $hospital = Hospital::factory()->create(['default_consultation_service_id' => $service->id]);

    $this->actingAs($user)->put(route('hospitals.update', $hospital), [
        'name' => $hospital->name,
        'code' => $hospital->code,
        'status' => 'active',
        'default_consultation_service_id' => null,
    ])->assertRedirect(route('hospitals.index'));

    expect($hospital->fresh()->default_consultation_service_id)->toBeNull();
});

test('setting a hospital default consultation service to a non-existent id is rejected', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('hospitals.store'), [
        'name' => 'X',
        'code' => 'XCODE',
        'status' => 'active',
        'default_consultation_service_id' => 999999,
    ])->assertSessionHasErrors('default_consultation_service_id');
});
