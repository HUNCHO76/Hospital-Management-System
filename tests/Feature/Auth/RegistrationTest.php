<?php

test('registration screen is disabled and returns not found', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration endpoint is disabled and rejects submissions', function () {
    $response = $this->post('/register', [
        'UserName' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
});
