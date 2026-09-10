<?php

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

function sensitiveRequest(): Request
{
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => null);

    return $request;
}

test('the audit service strips sensitive fields before storing values', function () {
    app(AuditLogService::class)->record(
        'user_updated',
        null,
        null,
        [
            'email' => 'safe@example.test',
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'remember_token' => 'abc123',
            'api_key' => 'key-live-999',
            'access_token' => 'tok-777',
            'client_secret' => 'shh',
            'nhif_api_key' => 'nhif-secret',
        ],
        sensitiveRequest(),
    );

    $log = AuditLog::query()->latest('id')->first();

    expect($log->new_values)->toHaveKey('email')
        ->and($log->new_values)->not->toHaveKey('password')
        ->and($log->new_values)->not->toHaveKey('password_confirmation')
        ->and($log->new_values)->not->toHaveKey('remember_token')
        ->and($log->new_values)->not->toHaveKey('api_key')
        ->and($log->new_values)->not->toHaveKey('access_token')
        ->and($log->new_values)->not->toHaveKey('client_secret')
        ->and($log->new_values)->not->toHaveKey('nhif_api_key');
});

test('no secret value survives anywhere in the stored audit row', function () {
    app(AuditLogService::class)->record(
        'user_updated',
        null,
        ['password' => 'old-secret-value'],
        ['password' => 'new-secret-value', 'token' => 'tok-secret-value'],
        sensitiveRequest(),
    );

    $log = AuditLog::query()->latest('id')->first();
    $serialized = json_encode([$log->old_values, $log->new_values]);

    expect($serialized)->not->toContain('old-secret-value')
        ->and($serialized)->not->toContain('new-secret-value')
        ->and($serialized)->not->toContain('tok-secret-value');
});

test('sanitize returns null for null values and preserves safe fields', function () {
    $service = app(AuditLogService::class);

    expect($service->sanitize(null))->toBeNull()
        ->and($service->sanitize(['first_name' => 'John', 'phone' => '0712']))
        ->toBe(['first_name' => 'John', 'phone' => '0712']);
});
