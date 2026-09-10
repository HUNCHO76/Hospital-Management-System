<?php

use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('isActive reflects the account status', function () {
    expect(User::factory()->create(['status' => 'active'])->isActive())->toBeTrue()
        ->and(User::factory()->create(['status' => 'suspended'])->isActive())->toBeFalse()
        ->and(User::factory()->create(['status' => 'inactive'])->isActive())->toBeFalse();
});

test('the password and remember token are hidden from array output', function () {
    $array = User::factory()->create()->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token');
});

test('the password is stored hashed, never in plain text', function () {
    $user = User::factory()->create(['password' => 'plain-text-secret']);

    expect($user->password)->not->toBe('plain-text-secret')
        ->and(Hash::check('plain-text-secret', $user->password))->toBeTrue();
});

test('a user has one staff profile', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->create(['user_id' => $user->id]);

    expect($user->staff)->toBeInstanceOf(Staff::class)
        ->and($user->staff->id)->toBe($staff->id);
});

test('a user has many audit logs', function () {
    $user = User::factory()->create();
    AuditLog::factory()->create(['user_id' => $user->id]);

    expect($user->auditLogs)->toHaveCount(1)
        ->and($user->auditLogs->first())->toBeInstanceOf(AuditLog::class);
});

test('a user can be assigned roles through the permission system', function () {
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $user->assignRole('doctor');

    expect($user->hasRole('doctor'))->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeFalse();
});

test('a user is soft deleted rather than removed', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});
