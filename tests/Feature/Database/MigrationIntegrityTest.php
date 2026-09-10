<?php

use App\Models\InsuranceProvider;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('all core Phase 1 tables are created by the migrations', function () {
    $tables = [
        'users', 'password_reset_tokens', 'sessions',
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'personal_access_tokens',
        'hospitals', 'departments', 'staff', 'practitioners',
        'patients', 'patient_contacts',
        'insurance_providers', 'insurance_schemes', 'patient_insurances',
        'system_settings', 'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [{$table}] to exist.");
    }
});

test('soft-deletable tables carry a deleted_at column', function () {
    // Practitioner is intentionally hard-deletable: it is an optional profile
    // attached to the soft-deletable Staff record, so it is excluded here.
    foreach (['users', 'hospitals', 'departments', 'staff', 'patients', 'insurance_providers', 'insurance_schemes'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue("Expected [{$table}.deleted_at] to exist.");
    }
});

test('the append-only audit log has no updated_at column', function () {
    expect(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

test('the unique constraint on user email is enforced', function () {
    User::factory()->create(['email' => 'unique@example.test']);

    expect(fn () => User::factory()->create(['email' => 'unique@example.test']))
        ->toThrow(QueryException::class);
});

test('the unique constraint on insurance provider code is enforced', function () {
    InsuranceProvider::factory()->create(['code' => 'DUP']);

    expect(fn () => InsuranceProvider::factory()->create(['code' => 'DUP']))
        ->toThrow(QueryException::class);
});

test('the permission and role seeders populate the authorization tables', function () {
    seedRolesAndPermissions();

    expect(Permission::count())->toBeGreaterThan(0)
        ->and(Role::whereIn('name', ['super-admin', 'hospital-admin', 'doctor', 'receptionist'])->count())->toBe(4);
});
