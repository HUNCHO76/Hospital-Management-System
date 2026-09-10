<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // A route registered purely for this test, protected the same way a real
    // permission-gated route would be (per section 14 of the spec). No real
    // '/patients' feature route exists yet — that belongs to a later step.
    Route::middleware(['web', 'auth', 'can:patients.view'])
        ->get('/__test/patients-view', fn () => response('ok'));
});

test('a guest is redirected to the login page', function () {
    $this->get('/__test/patients-view')->assertRedirect(route('login'));
});

test('an authorized user is allowed through', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');
    $user->givePermissionTo('patients.view');

    $this->actingAs($user)->get('/__test/patients-view')->assertOk();
});

test('an unauthorized authenticated user receives a 403', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');

    $this->actingAs($user)->get('/__test/patients-view')->assertForbidden();
});

test('the 403 response renders the Inertia error page', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');

    $this->actingAs($user)
        ->get('/__test/patients-view')
        ->assertStatus(403)
        ->assertInertia(fn ($page) => $page->component('errors/403'));
});

test('a super-admin bypasses permission middleware', function () {
    $user = User::factory()->create();
    Role::findOrCreate('super-admin');
    $user->assignRole('super-admin');
    Permission::findOrCreate('patients.view');

    $this->actingAs($user)->get('/__test/patients-view')->assertOk();
});

test('a user cannot bypass authorization by directly entering the url', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('patients.view');

    // No role, no direct permission — a direct hit on the URL must still be blocked.
    $this->actingAs($user)->get('/__test/patients-view')->assertForbidden();
});

test('the dashboard remains accessible to any authenticated user regardless of permissions', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk();
});
