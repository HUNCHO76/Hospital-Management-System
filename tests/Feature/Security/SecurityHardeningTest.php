<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Security regression tests (Step 13)
|--------------------------------------------------------------------------
| Each test locks in a control reviewed during the security hardening step so
| a regression cannot silently reintroduce the weakness.
|
| These tests originally targeted the internal /api/v1/patients and
| /api/v1/user endpoints. Those were removed in the HMS Route Architecture
| Migration (internal HMS functionality lives only in web.php/Inertia now —
| see docs/api-standards.md) since they had no consumer and no genuine
| external-integration need. The tests below verify the same guarantees
| against the web routes, which are the only surface left.
*/

test('baseline security headers are present on responses', function () {
    $response = $this->get(route('login'));

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

test('HSTS is not sent over plain http (local development is unaffected)', function () {
    expect($this->get(route('login'))->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('a staff creator cannot assign the super-admin role (privilege escalation)', function () {
    $admin = userWithRole('super-admin');

    $response = $this->actingAs($admin)->post(route('staff.store'), [
        'hospital_id' => Hospital::factory()->create()->id,
        'first_name' => 'Esc',
        'last_name' => 'Alation',
        'gender' => 'male',
        'employment_type' => 'full_time',
        'status' => 'active',
        'create_account' => true,
        'email' => 'escalate@example.test',
        'password' => 'password-123',
        'role' => 'super-admin',
    ]);

    $response->assertSessionHasErrors('role');
    expect(User::query()->where('email', 'escalate@example.test')->exists())->toBeFalse();
});

test('the patient show page never exposes internal/framework columns', function () {
    $admin = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $response = $this->actingAs($admin)->get(route('patients.show', $patient));

    $response->assertInertia(fn ($page) => $page->component('patients/show')
        ->where('patient.id', $patient->id)
        ->missing('patient.deleted_at')
        ->missing('patient.password'));
});

test('the shared auth user prop never exposes password or token fields', function () {
    $user = userWithRole('doctor');

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page->missing('auth.user.password')
        ->missing('auth.user.remember_token'));
});

test('a guest is redirected to login when reaching protected patient data', function () {
    $patient = Patient::factory()->create();

    $this->get(route('patients.show', $patient))->assertRedirect(route('login'));
});

test('an unauthorized role is denied and cannot escalate by changing HTTP method', function () {
    $pharmacist = userWithRole('pharmacist');
    $patient = Patient::factory()->create();

    // Pharmacist may not create or deactivate patients regardless of verb.
    $this->actingAs($pharmacist)
        ->post(route('patients.store'), ['first_name' => 'X', 'last_name' => 'Y', 'gender' => 'male', 'status' => 'active'])
        ->assertForbidden();
    $this->actingAs($pharmacist)->delete(route('patients.destroy', $patient))->assertForbidden();
});

test('mass assignment cannot smuggle a non-fillable column into a patient', function () {
    $admin = userWithRole('super-admin');

    $response = $this->actingAs($admin)->post(route('patients.store'), [
        'first_name' => 'Mass',
        'last_name' => 'Assign',
        'gender' => 'male',
        'status' => 'active',
        'id' => 999999,          // attempt to force a primary key
        'created_at' => '1990-01-01',
    ]);

    $response->assertRedirect();

    $patient = Patient::query()->where('first_name', 'Mass')->where('last_name', 'Assign')->firstOrFail();

    expect($patient->id)->not->toBe(999999);
    expect($patient->created_at->toDateString())->not->toBe('1990-01-01');
});

test('production-style errors never leak internal details', function () {
    $admin = userWithRole('super-admin');

    $response = $this->actingAs($admin)->get(route('patients.show', 999999));

    $response->assertStatus(404);
    $body = $response->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('vendor\\')
        ->and(strtolower($body))->not->toContain('stack trace');
});
