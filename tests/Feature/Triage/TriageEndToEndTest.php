<?php

use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\ReceptionVisit;
use App\Models\TriageAssessment;
use App\Models\VitalSign;

/**
 * Full OPD workflow (spec §47):
 *
 *   Appointment -> Reception Check-in -> Clinical Encounter (OPEN) ->
 *   Start Triage -> Record Vital Signs -> Assess Triage Category ->
 *   Complete Triage -> Ready for Consultation
 *
 * Verifies every entity stays correctly linked end to end, that separate
 * roles each only perform the part of the workflow they're authorized for,
 * and that every lifecycle transition is audited.
 */
test('a scheduled appointment flows through reception, encounter, and a completed triage assessment', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = appointmentBookingContext();

    $appointment = Appointment::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'practitioner_id' => $ctx['practitioner']->id,
        'status' => Appointment::STATUS_SCHEDULED,
    ]);

    $this->actingAs($receptionist)->post(route('reception.appointments.check-in', $appointment))->assertRedirect();
    $visit = ReceptionVisit::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'appointment_id' => $appointment->id,
        'encounter_type' => 'opd',
    ])->assertRedirect();
    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    expect($encounter->status)->toBe(ClinicalEncounter::STATUS_OPEN);

    // Start triage.
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $triage = TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    expect($triage->status)->toBe(TriageAssessment::STATUS_PENDING);

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    expect($triage->fresh()->status)->toBe(TriageAssessment::STATUS_IN_PROGRESS);

    // Record vital signs.
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), [
        'temperature_c' => 38.2,
        'systolic_mmhg' => 120,
        'diastolic_mmhg' => 80,
        'pulse_bpm' => 94,
        'respiratory_rate_bpm' => 18,
        'oxygen_saturation_pct' => 96,
        'weight_kg' => 70,
        'height_cm' => 175,
    ])->assertRedirect();

    $vitalSign = VitalSign::query()->where('triage_assessment_id', $triage->id)->firstOrFail();
    expect($vitalSign->clinical_encounter_id)->toBe($encounter->id);
    expect((float) $vitalSign->bmi)->toBe(22.86);

    // Assess triage category and chief complaint.
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), [
        'triage_category' => 'urgent',
        'chief_complaint' => 'Headache and fever for two days',
        'pain_score' => 5,
    ])->assertRedirect();

    expect($triage->fresh()->triage_category)->toBe('urgent');

    // Complete triage — patient now ready for consultation.
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    $fresh = $triage->fresh();
    expect($fresh->status)->toBe(TriageAssessment::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();

    // The clinical encounter itself is untouched by triage completion —
    // that transition belongs to the (future) consultation workflow.
    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);

    // Every lifecycle step left an audit trail.
    foreach (['triage_created', 'triage_started', 'vital_sign_recorded', 'triage_updated', 'triage_completed'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked.
    expect($fresh->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($vitalSign->triageAssessment->id)->toBe($fresh->id);
});

/**
 * Walk-in variant — verifies the triage/vitals workflow doesn't require an
 * appointment anywhere in the chain.
 */
test('a walk-in patient can complete the full triage workflow with no appointment involved', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();

    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ]);
    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit));
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit));

    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'walk_in',
    ]);
    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter));
    $triage = TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter));

    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 76]);
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'non_urgent']);
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    expect($triage->fresh()->status)->toBe(TriageAssessment::STATUS_COMPLETED);
    expect($encounter->fresh()->appointment_id)->toBeNull();
});
