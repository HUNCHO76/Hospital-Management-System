<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\ReceptionVisit;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

function auditFor(string $action, $auditable): void
{
    $service = app(AuditLogService::class);
    $request = Request::create('/', 'POST');
    $service->record($action, $auditable, null, [], $request);
}

test('a patient history timeline includes registration, insurance, appointment and reception events', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    auditFor('patient_created', $patient);

    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    auditFor('patient_insurance_created', $insurance);

    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
    ]);
    auditFor('appointment_created', $appointment);
    auditFor('appointment_confirmed', $appointment);

    $visit = ReceptionVisit::factory()->create(['patient_id' => $patient->id, 'department_id' => $ctx['department']->id]);
    auditFor('reception_visit_walkin_created', $visit);

    $response = $this->actingAs($user)->get(route('patients.history', $patient));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('timeline', 5)
        ->where('timeline.0.action', 'reception_visit_walkin_created')
    );
});

test('a patient history timeline never includes another patient\'s events', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();

    auditFor('patient_created', $patient);
    auditFor('patient_created', $otherPatient);

    $this->actingAs($user)
        ->get(route('patients.history', $patient))
        ->assertInertia(fn ($page) => $page->has('timeline', 1));
});

test('a guest is redirected to login for patient history', function () {
    $patient = Patient::factory()->create();

    $this->get(route('patients.history', $patient))->assertRedirect(route('login'));
});

test('a user without patients.view cannot view patient history', function () {
    $user = User::factory()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($user)->get(route('patients.history', $patient))->assertForbidden();
});

test('patient history never exposes raw audit old/new values', function () {
    $user = userWithRole('super-admin');
    $patient = Patient::factory()->create();

    $service = app(AuditLogService::class);
    $request = Request::create('/', 'POST');
    $service->record('patient_updated', $patient, ['first_name' => 'Old'], ['first_name' => 'New'], $request);

    $this->actingAs($user)
        ->get(route('patients.history', $patient))
        ->assertInertia(fn ($page) => $page->has(
            'timeline.0',
            fn ($entry) => $entry->has('id')->has('action')->has('module')->has('user')->has('created_at')
                ->missing('old_values')->missing('new_values'),
        ));
});
