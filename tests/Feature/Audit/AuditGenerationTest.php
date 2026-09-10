<?php

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceProviderService;
use App\Services\PatientService;
use App\Services\StaffService;
use Illuminate\Http\Request;

/**
 * Build a request bound to the given user, as controllers receive it.
 */
function auditRequest(?User $user = null): Request
{
    $request = Request::create('/test', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'Pom Test Agent',
    ]);

    $request->setUserResolver(fn () => $user);

    return $request;
}

test('creating a patient generates a patient_created audit event', function () {
    $service = app(PatientService::class);
    $data = Patient::factory()->make()->toArray();

    $patient = $service->create($data, auditRequest());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'patient_created',
        'auditable_type' => $patient->getMorphClass(),
        'auditable_id' => $patient->id,
    ]);
});

test('updating a patient generates a patient_updated audit event', function () {
    $service = app(PatientService::class);
    $patient = Patient::factory()->create();

    $service->update($patient, ['first_name' => 'Renamed'], auditRequest());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'patient_updated',
        'auditable_id' => $patient->id,
    ]);
});

test('deactivating a patient generates a patient_deactivated audit event', function () {
    $service = app(PatientService::class);
    $patient = Patient::factory()->create(['status' => Patient::STATUS_ACTIVE]);

    $service->deactivate($patient, auditRequest());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'patient_deactivated',
        'auditable_id' => $patient->id,
    ]);
});

test('creating staff generates a staff_created audit event', function () {
    $service = app(StaffService::class);
    $data = Staff::factory()->make()->toArray();

    $staff = $service->create($data, null, auditRequest());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'staff_created',
        'auditable_id' => $staff->id,
    ]);
});

test('creating an insurance provider generates an audit event', function () {
    $service = app(InsuranceProviderService::class);

    $provider = $service->create([
        'name' => 'Audited Provider',
        'code' => 'AUD',
        'type' => 'private',
        'status' => 'active',
    ], auditRequest());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'insurance_provider_created',
        'auditable_type' => $provider->getMorphClass(),
        'auditable_id' => $provider->id,
    ]);
});

test('a successful login is audited', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $this->assertDatabaseHas('audit_logs', ['action' => 'login_success', 'user_id' => $user->id]);
});

test('a failed login is audited and never records the password', function () {
    $user = User::factory()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed']);

    $leaked = AuditLog::query()
        ->get()
        ->contains(fn ($log) => str_contains(json_encode([$log->old_values, $log->new_values, $log->url]), 'wrong-password'));

    expect($leaked)->toBeFalse();
});

test('a logout is audited', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->post(route('logout'));

    $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $user->id]);
});

test('an audit event can be recorded with no authenticated user', function () {
    app(AuditLogService::class)->record('system_task', null, null, ['ran' => true], auditRequest(null));

    $this->assertDatabaseHas('audit_logs', ['action' => 'system_task', 'user_id' => null]);
});
