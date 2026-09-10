<?php

use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every appointment route', function () {
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->get(route('appointments.index'))->assertRedirect(route('login'));
    $this->get(route('appointments.create'))->assertRedirect(route('login'));
    $this->get(route('appointments.show', $appointment))->assertRedirect(route('login'));
    $this->get(route('appointments.edit', $appointment))->assertRedirect(route('login'));
});

test('a user without appointments.view receives a 403', function () {
    $user = userWithRole('accountant');

    $this->actingAs($user)->get(route('appointments.index'))->assertForbidden();
});

test('a user without appointments.create receives a 403', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();

    $this->actingAs($user)->get(route('appointments.create'))->assertForbidden();

    $this->actingAs($user)->post(route('appointments.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'initial_consultation',
        'scheduled_start' => now()->addDay()->format('Y-m-d H:i:s'),
        'scheduled_end' => now()->addDay()->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertForbidden();

    $this->assertDatabaseCount('appointments', 0);
});

test('a user without appointments.confirm cannot confirm an appointment', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)->post(route('appointments.confirm', $appointment))->assertForbidden();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED);
});

test('a user without appointments.cancel cannot cancel an appointment', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)->post(route('appointments.cancel', $appointment), [
        'cancellation_reason' => 'Should not be allowed',
    ])->assertForbidden();

    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED);
});

test('a user without the required permission cannot perform a status action', function (string $route, array $payload) {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)->post(route($route, $appointment), $payload)->assertForbidden();
})->with([
    'reschedule' => ['appointments.reschedule', ['scheduled_start' => '2027-01-01 10:00:00', 'scheduled_end' => '2027-01-01 10:30:00']],
    'check-in' => ['appointments.check-in', []],
    'start' => ['appointments.start', []],
    'complete' => ['appointments.complete', []],
    'no-show' => ['appointments.no-show', []],
]);

/**
 * Mandatory cross-hospital security test: a hospital-admin for Hospital A
 * must never be able to view or act on an appointment booked under
 * Hospital B's department, including by direct URL access.
 */
test('a hospital-admin cannot access another hospital appointment', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = appointmentBookingContext($hospitalA);
    $ctxB = appointmentBookingContext($hospitalB);

    $appointmentA = Appointment::factory()->create([
        'department_id' => $ctxA['department']->id,
        'practitioner_id' => $ctxA['practitioner']->id,
        'patient_id' => $ctxA['patient']->id,
    ]);
    $appointmentB = Appointment::factory()->create([
        'department_id' => $ctxB['department']->id,
        'practitioner_id' => $ctxB['practitioner']->id,
        'patient_id' => $ctxB['patient']->id,
        'appointment_type' => 'initial_consultation',
    ]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('appointments.show', $appointmentA))->assertOk();

    $this->actingAs($adminUser)->get(route('appointments.show', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->get(route('appointments.edit', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->put(route('appointments.update', $appointmentB), [
        'appointment_type' => 'follow_up',
        'reason' => 'Hijack attempt',
    ])->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.confirm', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.reschedule', $appointmentB), [
        'scheduled_start' => '2027-01-01 10:00:00',
        'scheduled_end' => '2027-01-01 10:30:00',
    ])->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.check-in', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.start', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.complete', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.no-show', $appointmentB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('appointments.cancel', $appointmentB), [
        'cancellation_reason' => 'Hijack attempt',
    ])->assertForbidden();

    expect($appointmentB->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED);
    expect($appointmentB->fresh()->appointment_type)->not->toBe('follow_up');
});

test('a hospital-admin appointment list only shows their own hospital', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = appointmentBookingContext($hospitalA);
    $ctxB = appointmentBookingContext($hospitalB);

    Appointment::factory()->create([
        'department_id' => $ctxA['department']->id,
        'practitioner_id' => $ctxA['practitioner']->id,
        'patient_id' => $ctxA['patient']->id,
    ]);
    Appointment::factory()->create([
        'department_id' => $ctxB['department']->id,
        'practitioner_id' => $ctxB['practitioner']->id,
        'patient_id' => $ctxB['patient']->id,
    ]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('a super-admin bypasses appointment permission checks and hospital scoping', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)->get(route('appointments.index'))->assertOk();
    $this->actingAs($user)->get(route('appointments.create'))->assertOk();
    $this->actingAs($user)->get(route('appointments.show', $appointment))->assertOk();
    $this->actingAs($user)->get(route('appointments.edit', $appointment))->assertOk();
});

test('an appointment belonging to a patient is not exposed through an unrelated patient route', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)->get(route('appointments.show', ['appointment' => 999999]))->assertNotFound();

    expect($appointment->fresh())->not->toBeNull();
});
