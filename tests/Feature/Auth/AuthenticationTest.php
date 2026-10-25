<?php

use App\Models\AuditLog;
use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can not authenticate with an unknown email', function () {
    $response = $this->post('/login', [
        'email' => 'unknown@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('email is required to login', function () {
    $response = $this->post('/login', [
        'email' => '',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('password is required to login', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => '',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('inactive users can not authenticate', function () {
    $user = User::factory()->create(['status' => User::STATUS_INACTIVE]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('suspended users can not authenticate', function () {
    $user = User::factory()->create(['status' => User::STATUS_SUSPENDED]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('a successful login is recorded in the audit log', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(AuditLog::query()->where('user_id', $user->id)->where('action', 'login_success')->exists())->toBeTrue();
});

test('a failed login is recorded in the audit log', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    expect(AuditLog::query()->where('user_id', $user->id)->where('action', 'login_failed')->exists())->toBeTrue();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false));
});

test('a logout is recorded in the audit log', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    expect(AuditLog::query()->where('user_id', $user->id)->where('action', 'logout')->exists())->toBeTrue();
});

test('inactivity logout redirects to login with an inactivity message', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout', ['inactivity' => true]);

    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false));
    $response->assertSessionHas('status', 'You have been logged out after 5 minutes of inactivity for security.');
});

test('logout does not require valid CSRF token and works on expired sessions', function () {
    $user = User::factory()->create();

    // Send POST /logout with an intentionally invalid CSRF token
    $response = $this->actingAs($user)->post('/logout', [
        '_token' => 'invalid-expired-csrf-token',
        'inactivity' => true,
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false));
});

test('non GET or POST requests to login route redirect cleanly instead of throwing 405', function () {
    $response = $this->put('/login');

    $response->assertRedirect(route('login', absolute: false));
    expect($response->status())->toBeIn([302, 303]);
});

test('unauthenticated Inertia PUT requests return Inertia location response to avoid method not allowed traps', function () {
    $user = User::factory()->create();

    $response = $this->putJson("/users/{$user->id}/permissions", [
        'overrides' => [
            ['permission_id' => 1, 'effect' => 'allow'],
        ],
    ], [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1.0.0',
    ]);

    // Inertia::location returns 409 with X-Inertia-Location header
    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(route('login'));
});

