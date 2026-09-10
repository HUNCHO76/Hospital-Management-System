<?php

use App\Models\Appointment;
use App\Models\ReceptionVisit;

function bookTodaysAppointment(array $overrides = []): array
{
    $ctx = appointmentBookingContext();

    $appointment = Appointment::factory()->create(array_merge([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHour()->addMinutes(30),
        'status' => Appointment::STATUS_SCHEDULED,
    ], $overrides));

    return compact('ctx', 'appointment');
}

test('a scheduled appointment can be checked in at reception', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment] = bookTodaysAppointment();

    $response = $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment));

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));

    expect($visit)->not->toBeNull();
    expect($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
    expect($visit->appointment_id)->toBe($appointment->id);
    expect($visit->visit_type)->toBe('appointment');
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CHECKED_IN);

    $this->assertDatabaseHas('audit_logs', ['action' => 'reception_visit_checked_in', 'auditable_id' => $visit->id]);
});

test('a cancelled appointment cannot be checked in', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment] = bookTodaysAppointment(['status' => Appointment::STATUS_CANCELLED]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CANCELLED);
});

test('a no-show appointment cannot be checked in', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment] = bookTodaysAppointment(['status' => Appointment::STATUS_NO_SHOW]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
});

test('a completed appointment cannot be checked in', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment] = bookTodaysAppointment(['status' => Appointment::STATUS_COMPLETED]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
});

test('an already checked-in appointment cannot be checked in again', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment] = bookTodaysAppointment(['status' => Appointment::STATUS_CHECKED_IN]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('status');

    $this->assertDatabaseCount('reception_visits', 0);
});

test('checking in defaults the visit department to the appointment department', function () {
    $user = userWithRole('receptionist');
    ['appointment' => $appointment, 'ctx' => $ctx] = bookTodaysAppointment();

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment));

    expect(ReceptionVisit::first()->department_id)->toBe($ctx['department']->id);
});
