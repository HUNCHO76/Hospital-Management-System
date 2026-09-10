<?php

use App\Models\Appointment;

test('appointments can be filtered by status', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();

    Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);
    Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
        'status' => Appointment::STATUS_CANCELLED,
    ]);

    $this->actingAs($user)
        ->get(route('appointments.index', ['status' => 'cancelled']))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('appointments can be filtered by department', function () {
    $user = userWithRole('super-admin');
    $ctxA = appointmentBookingContext();
    $ctxB = appointmentBookingContext();

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

    $this->actingAs($user)
        ->get(route('appointments.index', ['department_id' => $ctxA['department']->id]))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('appointments can be searched by appointment number', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();

    $appointment = Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)
        ->get(route('appointments.index', ['search' => $appointment->appointment_number]))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('appointments can be searched by patient name', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();
    $ctx['patient']->update(['first_name' => 'Zawadi', 'last_name' => 'Mushi']);

    Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)
        ->get(route('appointments.index', ['search' => 'Zawadi']))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('appointments can be filtered by a date range', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();

    Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
        'scheduled_start' => now()->addDays(3),
        'scheduled_end' => now()->addDays(3)->addMinutes(30),
    ]);
    Appointment::factory()->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
        'scheduled_start' => now()->addDays(10),
        'scheduled_end' => now()->addDays(10)->addMinutes(30),
    ]);

    $this->actingAs($user)
        ->get(route('appointments.index', [
            'date_from' => now()->addDays(1)->toDateString(),
            'date_to' => now()->addDays(5)->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
});

test('an unknown sort column is ignored rather than injected', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();

    Appointment::factory()->count(2)->create([
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($user)
        ->get(route('appointments.index', ['sort' => 'id); drop table appointments--']))
        ->assertOk();

    $this->assertDatabaseCount('appointments', 2);
});
