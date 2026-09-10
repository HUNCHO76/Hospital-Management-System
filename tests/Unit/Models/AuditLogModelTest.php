<?php

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;

test('moduleName derives the module from the auditable type', function () {
    $patient = Patient::factory()->create();
    $log = AuditLog::factory()->create([
        'action' => 'patient_updated',
        'auditable_type' => $patient->getMorphClass(),
        'auditable_id' => $patient->id,
    ]);

    expect($log->moduleName())->toBe('Patient');
});

test('moduleName groups authentication events without an auditable', function () {
    $log = AuditLog::factory()->create(['action' => 'login_success', 'auditable_type' => null, 'auditable_id' => null]);

    expect($log->moduleName())->toBe('Authentication');
});

test('moduleName labels other auditable-less events as system', function () {
    $log = AuditLog::factory()->create(['action' => 'system_task', 'auditable_type' => null, 'auditable_id' => null]);

    expect($log->moduleName())->toBe('System');
});

test('old and new values are cast to arrays', function () {
    $log = AuditLog::factory()->create([
        'old_values' => ['phone' => '0711'],
        'new_values' => ['phone' => '0722'],
    ]);

    $fresh = $log->fresh();

    expect($fresh->old_values)->toBe(['phone' => '0711'])
        ->and($fresh->new_values)->toBe(['phone' => '0722']);
});

test('an audit log belongs to the acting user', function () {
    $user = User::factory()->create();
    $log = AuditLog::factory()->create(['user_id' => $user->id]);

    expect($log->user)->toBeInstanceOf(User::class)
        ->and($log->user->id)->toBe($user->id);
});

test('an audit log resolves its polymorphic auditable', function () {
    $patient = Patient::factory()->create();
    $log = AuditLog::factory()->create([
        'auditable_type' => $patient->getMorphClass(),
        'auditable_id' => $patient->id,
    ]);

    expect($log->auditable)->toBeInstanceOf(Patient::class)
        ->and($log->auditable->id)->toBe($patient->id);
});

test('audit logs do not track an updated_at timestamp', function () {
    expect(AuditLog::UPDATED_AT)->toBeNull();
});
