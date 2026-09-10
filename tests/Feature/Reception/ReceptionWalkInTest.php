<?php

use App\Models\ReceptionVisit;

test('an authorized user can register a walk-in for an existing patient', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $response = $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ]);

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));

    expect($visit)->not->toBeNull();
    expect($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
    expect($visit->visit_type)->toBe('walk_in');
    expect($visit->appointment_id)->toBeNull();
    expect($visit->visit_number)->toStartWith('RV-'.now()->year.'-');

    $this->assertDatabaseHas('audit_logs', ['action' => 'reception_visit_walkin_created', 'auditable_id' => $visit->id]);
});

test('a nonexistent patient is rejected for a walk-in', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => 999999,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionHasErrors('patient_id');

    $this->assertDatabaseCount('reception_visits', 0);
});

test('an inactive department is rejected for a walk-in', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $ctx['department']->update(['status' => 'inactive']);

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertSessionHasErrors('department_id');

    $this->assertDatabaseCount('reception_visits', 0);
});

test('an invalid visit type is rejected for a walk-in', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'bogus',
    ])->assertSessionHasErrors('visit_type');
});
