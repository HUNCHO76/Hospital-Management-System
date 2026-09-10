<?php

use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\ReceptionVisit;

/**
 * Full appointment-based OPD workflow (spec §44):
 *
 *   Appointment -> Reception Check-in -> Ready for Service ->
 *   Clinical Encounter (OPEN) -> Start (IN_PROGRESS) -> Complete (COMPLETED)
 *
 * Verifies every entity stays correctly linked end to end and that separate
 * roles (reception vs. clinical) each only do the part of the workflow they
 * are authorized for.
 */
test('a scheduled appointment can flow through reception into a completed clinical encounter', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = appointmentBookingContext();

    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($receptionist)
        ->post(route('reception.appointments.check-in', $appointment))
        ->assertRedirect();

    $visit = ReceptionVisit::query()->where('appointment_id', $appointment->id)->firstOrFail();
    expect($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);

    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);

    $storeResponse = $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'appointment_id' => $appointment->id,
        'encounter_type' => 'opd',
    ]);
    $storeResponse->assertRedirect();

    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    expect($encounter->status)->toBe(ClinicalEncounter::STATUS_OPEN);
    expect($encounter->reception_visit_id)->toBe($visit->id);
    expect($encounter->appointment_id)->toBe($appointment->id);
    expect($encounter->department_id)->toBe($ctx['department']->id);

    $this->actingAs($nurse)->post(route('clinical-encounters.start', $encounter))->assertRedirect();
    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_IN_PROGRESS);
    expect($encounter->fresh()->started_at)->not->toBeNull();

    $this->actingAs($nurse)->post(route('clinical-encounters.complete', $encounter))->assertRedirect();
    $fresh = $encounter->fresh();
    expect($fresh->status)->toBe(ClinicalEncounter::STATUS_COMPLETED);
    expect($fresh->ended_at)->not->toBeNull();
    expect($fresh->closed_by)->toBe($nurse->id);

    // The patient, reception visit, and appointment all remain correctly
    // linked throughout — nothing was duplicated or detached.
    expect($fresh->patient_id)->toBe($ctx['patient']->id);
    expect($fresh->receptionVisit->id)->toBe($visit->id);
    expect($fresh->appointment->id)->toBe($appointment->id);
});

/**
 * Walk-in OPD workflow (spec §45):
 *
 *   Patient -> Walk-in Reception Visit -> Ready for Service ->
 *   Clinical Encounter (OPEN)
 *
 * Verifies no appointment is required for a legitimate walk-in.
 */
test('a walk-in patient can flow through reception into an open clinical encounter with no appointment', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();

    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertRedirect();

    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    expect($visit->appointment_id)->toBeNull();

    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'walk_in',
    ])->assertRedirect();

    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    expect($encounter->status)->toBe(ClinicalEncounter::STATUS_OPEN);
    expect($encounter->reception_visit_id)->toBe($visit->id);
    expect($encounter->appointment_id)->toBeNull();
    expect($encounter->practitioner_id)->toBeNull();
});

test('an encounter cannot be opened from a reception visit that is not yet ready for service', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();

    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ]);

    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    expect($visit->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'walk_in',
    ])->assertSessionHasErrors('reception_visit_id');

    $this->assertDatabaseCount('clinical_encounters', 0);
});

test('an encounter cannot be opened against a reception visit belonging to another patient', function () {
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $otherPatient = Patient::factory()->create(['status' => Patient::STATUS_ACTIVE]);

    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $otherPatient->id,
        'department_id' => $ctx['department']->id,
        'status' => ReceptionVisit::STATUS_READY_FOR_SERVICE,
    ]);

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'opd',
    ])->assertSessionHasErrors('reception_visit_id');

    $this->assertDatabaseCount('clinical_encounters', 0);
});
