<?php

use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every reception route', function () {
    $ctx = appointmentBookingContext();
    $visit = ReceptionVisit::factory()->create(['patient_id' => $ctx['patient']->id, 'department_id' => $ctx['department']->id]);

    $this->get(route('reception.dashboard'))->assertRedirect(route('login'));
    $this->get(route('reception.visits.index'))->assertRedirect(route('login'));
    $this->get(route('reception.visits.show', $visit))->assertRedirect(route('login'));
    $this->get(route('reception.walk-in.create'))->assertRedirect(route('login'));
});

test('a user without reception.dashboard receives a 403', function () {
    $user = userWithRole('accountant');

    $this->actingAs($user)->get(route('reception.dashboard'))->assertForbidden();
});

test('a user without reception.view receives a 403 on the visit list', function () {
    $user = userWithRole('accountant');

    $this->actingAs($user)->get(route('reception.visits.index'))->assertForbidden();
});

test('a user without reception.update cannot progress a visit\'s status', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $visit = ReceptionVisit::factory()->create(['patient_id' => $ctx['patient']->id, 'department_id' => $ctx['department']->id]);

    $this->actingAs($user)->post(route('reception.visits.waiting', $visit))->assertForbidden();
    $this->actingAs($user)->post(route('reception.visits.ready', $visit))->assertForbidden();
    $this->actingAs($user)->post(route('reception.visits.send-to-service', $visit))->assertForbidden();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
});

test('a user without reception.complete cannot complete a visit', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_SENT_TO_SERVICE,
    ]);

    $this->actingAs($user)->post(route('reception.visits.complete', $visit))->assertForbidden();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_SENT_TO_SERVICE);
});

test('a user without reception.create_walkin cannot register a walk-in', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();

    $this->actingAs($user)->get(route('reception.walk-in.create'))->assertForbidden();

    $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertForbidden();

    $this->assertDatabaseCount('reception_visits', 0);
});

test('a user without reception.checkin cannot check in an appointment', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
    ]);

    $this->actingAs($user)->post(route('reception.appointments.check-in', $appointment))->assertForbidden();

    $this->assertDatabaseCount('reception_visits', 0);
});

test('a user without reception.cancel cannot cancel a visit', function () {
    $user = userWithRole('accountant');
    $ctx = appointmentBookingContext();
    $visit = ReceptionVisit::factory()->create(['patient_id' => $ctx['patient']->id, 'department_id' => $ctx['department']->id]);

    $this->actingAs($user)->post(route('reception.visits.cancel', $visit), ['cancellation_reason' => 'nope'])->assertForbidden();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
});

/**
 * Mandatory cross-hospital security test: a hospital-admin for Hospital A
 * must never be able to view or act on a visit booked under Hospital B's
 * department, including by direct URL access.
 */
test('a hospital-admin cannot access another hospital reception visit', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = appointmentBookingContext($hospitalA);
    $ctxB = appointmentBookingContext($hospitalB);

    $visitA = ReceptionVisit::factory()->create(['patient_id' => $ctxA['patient']->id, 'department_id' => $ctxA['department']->id]);
    $visitB = ReceptionVisit::factory()->create(['patient_id' => $ctxB['patient']->id, 'department_id' => $ctxB['department']->id]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)->get(route('reception.visits.show', $visitA))->assertOk();

    $this->actingAs($adminUser)->get(route('reception.visits.show', $visitB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('reception.visits.waiting', $visitB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('reception.visits.ready', $visitB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('reception.visits.send-to-service', $visitB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('reception.visits.complete', $visitB))->assertForbidden();
    $this->actingAs($adminUser)->post(route('reception.visits.cancel', $visitB), ['cancellation_reason' => 'Hijack'])->assertForbidden();

    expect($visitB->fresh()->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
});

test('a hospital-admin visit list only shows their own hospital', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = appointmentBookingContext($hospitalA);
    $ctxB = appointmentBookingContext($hospitalB);

    ReceptionVisit::factory()->create(['patient_id' => $ctxA['patient']->id, 'department_id' => $ctxA['department']->id]);
    ReceptionVisit::factory()->create(['patient_id' => $ctxB['patient']->id, 'department_id' => $ctxB['department']->id]);

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->get(route('reception.visits.index'))
        ->assertInertia(fn ($page) => $page->where('visits.total', 1));
});

test('a super-admin bypasses reception permission checks and hospital scoping', function () {
    $user = userWithRole('super-admin');
    $ctx = appointmentBookingContext();
    $visit = ReceptionVisit::factory()->create(['patient_id' => $ctx['patient']->id, 'department_id' => $ctx['department']->id]);

    $this->actingAs($user)->get(route('reception.dashboard'))->assertOk();
    $this->actingAs($user)->get(route('reception.visits.index'))->assertOk();
    $this->actingAs($user)->get(route('reception.visits.show', $visit))->assertOk();
    $this->actingAs($user)->get(route('reception.walk-in.create'))->assertOk();
});
