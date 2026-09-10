<?php

test('the health endpoint returns a healthy standard response', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'HMS is healthy.',
            'data' => ['status' => 'ok'],
        ])
        ->assertJsonStructure(['success', 'message', 'data' => ['status', 'time'], 'request_id']);

    $response->assertHeader('X-Request-ID');
});

test('the health endpoint never leaks configuration or secrets', function () {
    $body = $this->getJson('/api/health')->getContent();

    expect($body)->not->toContain('APP_KEY')
        ->and($body)->not->toContain('DB_PASSWORD')
        ->and(strtolower($body))->not->toContain('password');
});

test('the health endpoint is public and needs no authentication', function () {
    $this->getJson('/api/health')->assertOk();
});
