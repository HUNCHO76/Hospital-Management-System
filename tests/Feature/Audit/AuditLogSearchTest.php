<?php

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;

test('audit logs can be searched by user email', function () {
    $admin = userWithRole('super-admin');
    $actor = User::factory()->create(['email' => 'findme@example.test']);
    AuditLog::factory()->create(['user_id' => $actor->id, 'action' => 'patient_updated']);
    AuditLog::factory()->create(['action' => 'staff_created']);

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['search' => 'findme@example.test']))
        ->assertInertia(fn ($page) => $page->where('logs.total', 1));
});

test('audit logs can be filtered by action', function () {
    $admin = userWithRole('super-admin');
    AuditLog::factory()->create(['action' => 'patient_created']);
    AuditLog::factory()->create(['action' => 'staff_created']);

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['action' => 'patient_created']))
        ->assertInertia(fn ($page) => $page->where('logs.total', 1));
});

test('audit logs can be filtered by module', function () {
    $admin = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    AuditLog::factory()->create([
        'action' => 'patient_updated',
        'auditable_type' => $patient->getMorphClass(),
        'auditable_id' => $patient->id,
    ]);
    AuditLog::factory()->create(['action' => 'login_success', 'auditable_type' => null, 'auditable_id' => null]);

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['module' => 'Patient']))
        ->assertInertia(fn ($page) => $page->where('logs.total', 1));
});

test('audit logs can be filtered by record id', function () {
    $admin = userWithRole('super-admin');
    AuditLog::factory()->create(['auditable_type' => 'App\\Models\\Patient', 'auditable_id' => 12345]);
    AuditLog::factory()->create(['auditable_type' => 'App\\Models\\Patient', 'auditable_id' => 99999]);

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['search' => '12345']))
        ->assertInertia(fn ($page) => $page->where('logs.total', 1));
});

test('audit logs can be filtered by a date range', function () {
    $admin = userWithRole('super-admin');
    AuditLog::factory()->create(['created_at' => '2026-01-10 09:00:00']);
    AuditLog::factory()->create(['created_at' => '2026-06-15 09:00:00']);

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['date_from' => '2026-06-01', 'date_to' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page->where('logs.total', 1));
});

test('the index exposes the distinct actions and modules present as filter options', function () {
    $admin = userWithRole('super-admin');
    AuditLog::factory()->create(['action' => 'patient_created', 'auditable_type' => 'App\\Models\\Patient', 'auditable_id' => 1]);
    AuditLog::factory()->create(['action' => 'login_success', 'auditable_type' => null, 'auditable_id' => null]);

    $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filterOptions.actions', fn ($actions) => in_array('patient_created', $actions->all(), true))
            ->where('filterOptions.modules', fn ($modules) => in_array('Patient', $modules->all(), true)));
});
