<?php

use App\Models\AuditLog;
use App\Models\User;

test('an authorized administrator can view the audit log list', function () {
    $user = userWithRole('super-admin');
    AuditLog::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit-logs/index'));
});

test('an authorized administrator can view a single audit log entry', function () {
    $user = userWithRole('super-admin');
    $log = AuditLog::factory()->create();

    $this->actingAs($user)
        ->get(route('audit-logs.show', $log))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit-logs/show')->where('auditLog.id', $log->id));
});

test('hospital-admin is granted audit log access', function () {
    $user = userWithRole('hospital-admin');
    AuditLog::factory()->create();

    $this->actingAs($user)->get(route('audit-logs.index'))->assertOk();
});

test('a user without audit-logs.view cannot view audit logs', function () {
    $user = userWithRole('receptionist');
    $log = AuditLog::factory()->create();

    $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();
    $this->actingAs($user)->get(route('audit-logs.show', $log))->assertForbidden();
});

test('a plain user with no role cannot view audit logs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();
});

test('a guest is redirected to login for audit log routes', function () {
    $this->get(route('audit-logs.index'))->assertRedirect(route('login'));
});

test('audit logs are paginated', function () {
    $user = userWithRole('super-admin');
    AuditLog::factory()->count(25)->create();

    $this->actingAs($user)
        ->get(route('audit-logs.index'))
        ->assertInertia(fn ($page) => $page
            ->where('logs.per_page', 20)
            ->where('logs.total', 25)
            ->count('logs.data', 20));
});

test('audit logs cannot be created, edited, or deleted through the web ui', function () {
    $user = userWithRole('super-admin');
    $log = AuditLog::factory()->create();

    // No such routes exist; the audit trail is immutable from the application.
    $this->actingAs($user)->post(route('audit-logs.index'))->assertStatus(405);
    $this->actingAs($user)->put(route('audit-logs.show', $log))->assertStatus(405);
    $this->actingAs($user)->delete(route('audit-logs.show', $log))->assertStatus(405);

    $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
});
