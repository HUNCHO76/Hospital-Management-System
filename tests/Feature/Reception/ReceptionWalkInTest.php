<?php

use App\Models\Charge;
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

test('a walk-in with a practitioner but no service price configured flashes a billing warning without blocking the visit', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    // Deliberately no consultation_service_id on the practitioner and no
    // default_consultation_service_id on the hospital — the pricing gap
    // this feature is meant to surface.

    $response = $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
    ]);

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));
    $response->assertSessionHas('warning');

    $this->assertDatabaseCount('charges', 0);
    $this->assertDatabaseCount('patient_accounts', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_capture_skipped']);
});

test('a walk-in with a properly priced consultation service captures a charge, provisions a patient account, and flashes no warning', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $service = billableConsultationService($ctx['hospital'], ['amount' => 20000]);
    $ctx['practitioner']->update(['consultation_service_id' => $service->id]);

    $response = $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $ctx['practitioner']->id,
    ]);

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));
    $response->assertSessionMissing('warning');

    $this->assertDatabaseCount('charges', 1);
    $this->assertDatabaseCount('patient_accounts', 1);

    $charge = Charge::first();
    expect($charge->source_type)->toBe(ReceptionVisit::class);
    expect($charge->source_id)->toBe($visit->id);
    expect($charge->service_id)->toBe($service->id);
    expect((float) $charge->unit_price)->toBe(20000.0);
});
