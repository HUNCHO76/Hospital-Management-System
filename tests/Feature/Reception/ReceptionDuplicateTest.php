<?php

use App\Models\Appointment;
use App\Models\ReceptionVisit;

test('a second walk-in for a patient with an active visit is rejected', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ]);

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionHasErrors('patient_id');

    $this->assertDatabaseCount('reception_visits', 1);
});

test('checking in an appointment for a patient with an existing active visit is rejected', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_WAITING,
    ]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => now()->addHour(),
        'scheduled_end' => now()->addHour()->addMinutes(30),
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))
        ->assertSessionHasErrors('patient_id');

    $this->assertDatabaseCount('reception_visits', 1);
    expect($appointment->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED);
});

test('a new visit is allowed once the patient\'s previous visit is completed', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $first = ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_SENT_TO_SERVICE,
    ]);
    $first->update(['status' => ReceptionVisit::STATUS_COMPLETED, 'completed_at' => now()]);

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('reception_visits', 2);
});

test('a new visit is allowed once the patient\'s previous visit is cancelled', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $first = ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
    ]);
    $first->update(['status' => ReceptionVisit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'Left']);

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('reception_visits', 2);
});

test('a new visit is allowed if the patient has a completed clinical encounter even if the visit was left in ready_for_service', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_READY_FOR_SERVICE,
    ]);

    \App\Models\ClinicalEncounter::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => \App\Models\ClinicalEncounter::STATUS_COMPLETED,
        'reception_visit_id' => $visit->id,
        'ended_at' => now(),
    ]);

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionDoesntHaveErrors();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_COMPLETED);
    $this->assertDatabaseCount('reception_visits', 2);
});

