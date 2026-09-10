<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\Staff;

test('an overlapping appointment for the same practitioner is rejected', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $start = now()->addDay()->setTime(10, 0);
    $end = (clone $start)->addMinutes(30);

    Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $overlappingStart = (clone $start)->addMinutes(15);
    $overlappingEnd = (clone $overlappingStart)->addMinutes(30);

    $response = $this->actingAs($user)->post(route('appointments.store'), [
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'follow_up',
        'scheduled_start' => $overlappingStart->format('Y-m-d H:i:s'),
        'scheduled_end' => $overlappingEnd->format('Y-m-d H:i:s'),
    ]);

    $response->assertSessionHasErrors('scheduled_start');
    $this->assertDatabaseCount('appointments', 1);
});

test('a non-overlapping appointment for the same practitioner is allowed', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $start = now()->addDay()->setTime(10, 0);
    $end = (clone $start)->addMinutes(30);

    Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $laterStart = (clone $end);
    $laterEnd = (clone $laterStart)->addMinutes(30);

    $this->actingAs($user)->post(route('appointments.store'), [
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'follow_up',
        'scheduled_start' => $laterStart->format('Y-m-d H:i:s'),
        'scheduled_end' => $laterEnd->format('Y-m-d H:i:s'),
    ])->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('appointments', 2);
});

test('the same time slot with a different practitioner is not a conflict', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $otherStaff = Staff::factory()->create(['hospital_id' => $ctx['hospital']->id]);
    $otherPractitioner = Practitioner::factory()->create(['staff_id' => $otherStaff->id]);

    $start = now()->addDay()->setTime(10, 0);
    $end = (clone $start)->addMinutes(30);

    Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)->post(route('appointments.store'), [
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $otherPractitioner->id,
        'appointment_type' => 'follow_up',
        'scheduled_start' => $start->format('Y-m-d H:i:s'),
        'scheduled_end' => $end->format('Y-m-d H:i:s'),
    ])->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('appointments', 2);
});

test('a cancelled appointment does not count toward a conflict', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $start = now()->addDay()->setTime(10, 0);
    $end = (clone $start)->addMinutes(30);

    Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
        'status' => Appointment::STATUS_CANCELLED,
    ]);

    $this->actingAs($user)->post(route('appointments.store'), [
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'appointment_type' => 'follow_up',
        'scheduled_start' => $start->format('Y-m-d H:i:s'),
        'scheduled_end' => $end->format('Y-m-d H:i:s'),
    ])->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('appointments', 2);
});

test('rescheduling into another appointment\'s slot for the same practitioner is rejected', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $firstStart = now()->addDay()->setTime(9, 0);
    $firstEnd = (clone $firstStart)->addMinutes(30);
    $first = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $firstStart,
        'scheduled_end' => $firstEnd,
    ]);

    $secondStart = now()->addDay()->setTime(11, 0);
    $secondEnd = (clone $secondStart)->addMinutes(30);
    $second = Appointment::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $secondStart,
        'scheduled_end' => $secondEnd,
    ]);

    $this->actingAs($user)->post(route('appointments.reschedule', $second), [
        'scheduled_start' => $firstStart->format('Y-m-d H:i:s'),
        'scheduled_end' => $firstEnd->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('scheduled_start');

    expect($second->fresh()->scheduled_start->format('Y-m-d H:i:s'))->toBe($secondStart->format('Y-m-d H:i:s'));
});

test('rescheduling an appointment against its own current slot is not a conflict with itself', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $start = now()->addDay()->setTime(9, 0);
    $end = (clone $start)->addMinutes(30);
    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'scheduled_start' => $start,
        'scheduled_end' => $end,
    ]);

    $newEnd = (clone $end)->addMinutes(15);

    $this->actingAs($user)->post(route('appointments.reschedule', $appointment), [
        'scheduled_start' => $start->format('Y-m-d H:i:s'),
        'scheduled_end' => $newEnd->format('Y-m-d H:i:s'),
    ])->assertSessionDoesntHaveErrors();

    expect($appointment->fresh()->scheduled_end->format('Y-m-d H:i:s'))->toBe($newEnd->format('Y-m-d H:i:s'));
});
