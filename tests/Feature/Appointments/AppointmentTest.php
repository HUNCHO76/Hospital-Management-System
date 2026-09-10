<?php

use App\Models\Appointment;

function bookAppointment(array $overrides = []): array
{
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $payload = array_merge([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'initial_consultation',
        'scheduled_start' => now()->addDay()->format('Y-m-d H:i:s'),
        'scheduled_end' => now()->addDay()->addMinutes(30)->format('Y-m-d H:i:s'),
        'reason' => 'Routine checkup',
    ], $overrides);

    return compact('user', 'ctx', 'payload');
}

test('an authorized user can book an appointment', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();

    $response = $this->actingAs($user)->post(route('appointments.store'), $payload);

    $appointment = Appointment::first();
    $response->assertRedirect(route('appointments.show', $appointment));

    expect($appointment)->not->toBeNull();
    expect($appointment->appointment_number)->toStartWith('APT-'.now()->year.'-');
    expect($appointment->status)->toBe(Appointment::STATUS_SCHEDULED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_created', 'auditable_id' => $appointment->id]);
});

test('a nonexistent patient is rejected', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment(['patient_id' => 999999]);

    $this->actingAs($user)->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('patient_id');

    $this->assertDatabaseCount('appointments', 0);
});

test('an inactive practitioner is rejected', function () {
    ['user' => $user, 'ctx' => $ctx, 'payload' => $payload] = bookAppointment();
    $ctx['practitioner']->update(['status' => 'inactive']);

    $this->actingAs($user)->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('practitioner_id');
});

test('a practitioner from a different hospital than the department is rejected', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $otherCtx = appointmentBookingContext();

    $payload['practitioner_id'] = $otherCtx['practitioner']->id;

    $this->actingAs($user)->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('practitioner_id');
});

test('an appointment in the past is rejected', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment([
        'scheduled_start' => now()->subDay()->format('Y-m-d H:i:s'),
        'scheduled_end' => now()->subDay()->addMinutes(30)->format('Y-m-d H:i:s'),
    ]);

    $this->actingAs($user)->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('scheduled_start');
});

test('an end time before the start time is rejected', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment([
        'scheduled_start' => now()->addDay()->format('Y-m-d H:i:s'),
        'scheduled_end' => now()->addDay()->subMinutes(30)->format('Y-m-d H:i:s'),
    ]);

    $this->actingAs($user)->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('scheduled_end');
});

test('an appointment can be rescheduled to a new non-conflicting time', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $newStart = now()->addDays(2)->format('Y-m-d H:i:s');
    $newEnd = now()->addDays(2)->addMinutes(30)->format('Y-m-d H:i:s');

    $this->actingAs($user)->post(route('appointments.reschedule', $appointment), [
        'scheduled_start' => $newStart,
        'scheduled_end' => $newEnd,
    ])->assertRedirect(route('appointments.show', $appointment));

    expect($appointment->fresh()->scheduled_start->format('Y-m-d H:i:s'))->toBe($newStart);
    $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_rescheduled', 'auditable_id' => $appointment->id]);
});

test('the non-scheduling fields of an appointment can be updated', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $this->actingAs($user)->put(route('appointments.update', $appointment), [
        'appointment_type' => 'follow_up',
        'reason' => 'Updated reason',
        'notes' => 'Some notes',
    ])->assertRedirect(route('appointments.show', $appointment));

    expect($appointment->fresh()->appointment_type)->toBe('follow_up');
    expect($appointment->fresh()->notes)->toBe('Some notes');
});

test('the appointment lifecycle can progress through confirm, check-in, start and complete', function () {
    // Booking/confirming is a front-desk (receptionist) action; check-in is
    // also front-desk, while starting/completing the visit is a clinical
    // (doctor) action — matching RoleSeeder's actual permission split.
    ['user' => $receptionist, 'payload' => $payload] = bookAppointment();
    $this->actingAs($receptionist)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $this->actingAs($receptionist)->post(route('appointments.confirm', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CONFIRMED);

    $this->actingAs($receptionist)->post(route('appointments.check-in', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_CHECKED_IN);

    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->post(route('appointments.start', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_IN_PROGRESS);

    $this->actingAs($doctor)->post(route('appointments.complete', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_COMPLETED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_completed', 'auditable_id' => $appointment->id]);
});

test('an appointment can be cancelled with a reason', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $this->actingAs($user)->post(route('appointments.cancel', $appointment), [
        'cancellation_reason' => 'Patient requested cancellation',
    ])->assertRedirect();

    $appointment->refresh();
    expect($appointment->status)->toBe(Appointment::STATUS_CANCELLED);
    expect($appointment->cancellation_reason)->toBe('Patient requested cancellation');
    expect($appointment->cancelled_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_cancelled', 'auditable_id' => $appointment->id]);
});

test('cancelling without a reason is rejected', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $this->actingAs($user)->post(route('appointments.cancel', $appointment), [])
        ->assertSessionHasErrors('cancellation_reason');
});

test('an appointment can be marked a no-show', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $this->actingAs($user)->post(route('appointments.no-show', $appointment))->assertRedirect();

    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_NO_SHOW);
});

test('a completed appointment cannot transition back to scheduled', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();

    $appointment->update(['status' => Appointment::STATUS_COMPLETED]);

    $this->actingAs($user)->post(route('appointments.confirm', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_COMPLETED);
});

test('a cancelled appointment cannot be marked a no-show', function () {
    ['user' => $user, 'payload' => $payload] = bookAppointment();
    $this->actingAs($user)->post(route('appointments.store'), $payload);
    $appointment = Appointment::first();
    $appointment->update(['status' => Appointment::STATUS_CANCELLED]);

    $this->actingAs($user)->post(route('appointments.no-show', $appointment))
        ->assertSessionHasErrors('status');
});
