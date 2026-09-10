<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('an authorized user can view the departments list', function () {
    $user = userWithRole('super-admin');
    Department::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('departments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('departments/index'));
});

test('an authorized user can create a department', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();

    $response = $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $hospital->id,
        'name' => 'Cardiology',
        'code' => 'CARD',
        'description' => 'Heart and cardiovascular care',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('departments.index'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('departments', ['code' => 'CARD', 'hospital_id' => $hospital->id]);
});

test('an authorized user can update a department', function () {
    $user = userWithRole('super-admin');
    $department = Department::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('departments.update', $department), [
        'hospital_id' => $department->hospital_id,
        'name' => 'New Name',
        'code' => $department->code,
        'description' => $department->description,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('departments.index'));
    expect($department->fresh()->name)->toBe('New Name');
});

test('an authorized user can delete a department', function () {
    $user = userWithRole('super-admin');
    $department = Department::factory()->create();

    $response = $this->actingAs($user)->delete(route('departments.destroy', $department));

    $response->assertRedirect(route('departments.index'));
    $this->assertSoftDeleted($department);
});

test('a department with staff cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $department = Department::factory()->create();
    Staff::factory()->create(['department_id' => $department->id, 'hospital_id' => $department->hospital_id]);

    $this->actingAs($user)->delete(route('departments.destroy', $department));

    $this->assertDatabaseHas('departments', ['id' => $department->id, 'deleted_at' => null]);
});

test('duplicate department code within the same hospital is rejected', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();
    Department::factory()->create(['hospital_id' => $hospital->id, 'code' => 'OPD']);

    $response = $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $hospital->id,
        'name' => 'Outpatient Duplicate',
        'code' => 'OPD',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('code');
});

test('the same department code is allowed in a different hospital', function () {
    $user = userWithRole('super-admin');
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    Department::factory()->create(['hospital_id' => $hospitalA->id, 'code' => 'OPD']);

    $response = $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $hospitalB->id,
        'name' => 'Outpatient',
        'code' => 'OPD',
        'status' => 'active',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('departments', ['hospital_id' => $hospitalB->id, 'code' => 'OPD']);
});

test('a department may keep its own code when updated', function () {
    $user = userWithRole('super-admin');
    $department = Department::factory()->create(['code' => 'KEEP']);

    $response = $this->actingAs($user)->put(route('departments.update', $department), [
        'hospital_id' => $department->hospital_id,
        'name' => $department->name,
        'code' => 'KEEP',
        'status' => 'active',
    ]);

    $response->assertSessionHasNoErrors();
});

test('missing required department fields are rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('departments.store'), []);

    $response->assertSessionHasErrors(['hospital_id', 'name', 'code', 'status']);
});

test('an invalid hospital_id is rejected', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => 999999,
        'name' => 'Ghost Department',
        'code' => 'GHOST',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('hospital_id');
});

test('an invalid department status is rejected', function () {
    $user = userWithRole('super-admin');
    $hospital = Hospital::factory()->create();

    $response = $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $hospital->id,
        'name' => 'Test',
        'code' => 'TST',
        'status' => 'archived',
    ]);

    $response->assertSessionHasErrors('status');
});

test('departments can be searched by name', function () {
    $user = userWithRole('super-admin');
    Department::factory()->create(['name' => 'Findable Department', 'code' => 'FIND']);
    Department::factory()->create(['name' => 'Other Department', 'code' => 'OTHR']);

    $response = $this->actingAs($user)->get(route('departments.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page->where('departments.total', 1));
});

test('departments can be filtered by hospital', function () {
    $user = userWithRole('super-admin');
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    Department::factory()->create(['hospital_id' => $hospitalA->id]);
    Department::factory()->create(['hospital_id' => $hospitalB->id]);

    $response = $this->actingAs($user)->get(route('departments.index', ['hospital_id' => $hospitalA->id]));

    $response->assertInertia(fn ($page) => $page->where('departments.total', 1));
});

test('departments can be filtered by status', function () {
    $user = userWithRole('super-admin');
    Department::factory()->create(['status' => 'active']);
    Department::factory()->create(['status' => 'inactive']);

    $response = $this->actingAs($user)->get(route('departments.index', ['status' => 'inactive']));

    $response->assertInertia(fn ($page) => $page->where('departments.total', 1));
});

test('departments list is paginated', function () {
    $user = userWithRole('super-admin');
    Department::factory()->count(20)->create();

    $response = $this->actingAs($user)->get(route('departments.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('departments.per_page', 15)
        ->has('departments.data', 15)
    );
});

test('a hospital-admin can only manage departments within their own hospital', function () {
    seedRolesAndPermissions();

    $myHospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $otherDepartment = Department::factory()->create(['hospital_id' => $otherHospital->id]);

    $user = User::factory()->create();
    $user->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $myHospital->id]);

    $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $myHospital->id,
        'name' => 'My Department',
        'code' => 'MINE',
        'status' => 'active',
    ])->assertRedirect(route('departments.index'));

    $this->actingAs($user)->post(route('departments.store'), [
        'hospital_id' => $otherHospital->id,
        'name' => 'Not Mine',
        'code' => 'NOTMINE',
        'status' => 'active',
    ])->assertForbidden();

    $this->actingAs($user)
        ->put(route('departments.update', $otherDepartment), [
            'hospital_id' => $otherHospital->id,
            'name' => 'Hijacked',
            'code' => $otherDepartment->code,
            'status' => 'active',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('departments', ['code' => 'NOTMINE']);
    expect($otherDepartment->fresh()->name)->not->toBe('Hijacked');
});
