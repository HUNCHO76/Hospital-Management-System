<?php

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\TriageAssessment;

/**
 * Full clinical chain (spec §40):
 *
 *   Clinical Encounter -> Triage Completed -> Vital Signs ->
 *   Consultation Started -> Clinical Documentation -> Consultation Completed
 *
 * Verifies every entity stays correctly linked end to end and that every
 * lifecycle transition is audited.
 */
test('a completed triage flows into a full doctor consultation over HTTP', function () {
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $triage = TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();
    expect($triage->fresh()->status)->toBe(TriageAssessment::STATUS_COMPLETED);

    // Doctor starts the consultation.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $consultation = Consultation::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    expect($consultation->status)->toBe(Consultation::STATUS_PENDING);
    expect($consultation->practitioner_id)->toBe($doctor['practitioner']->id);

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();
    expect($consultation->fresh()->status)->toBe(Consultation::STATUS_IN_PROGRESS);

    // Document the clinical assessment.
    $this->actingAs($doctor['user'])->put(route('clinical-encounters.consultation.update', $encounter), [
        'chief_complaint' => 'Persistent abdominal pain for three days',
        'history_presenting_illness' => 'Gradual onset, worsening with meals, no relieving factors identified.',
        'physical_examination' => 'Mild tenderness in the epigastric region, no rebound or guarding.',
        'assessment_summary' => 'Clinically stable. Findings consistent with gastritis.',
        'plan_summary' => 'Start antacids, review in 48 hours.',
    ])->assertRedirect();

    expect($consultation->fresh()->chief_complaint)->toBe('Persistent abdominal pain for three days');

    // Complete the consultation.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.complete', $encounter))->assertRedirect();

    $fresh = $consultation->fresh();
    expect($fresh->status)->toBe(Consultation::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();
    expect($fresh->completed_by)->toBe($doctor['user']->id);

    // The clinical encounter itself is untouched by consultation completion.
    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);

    // Every lifecycle step left an audit trail.
    foreach (['consultation_created', 'consultation_started', 'consultation_updated', 'consultation_completed'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked.
    expect($fresh->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($fresh->clinicalEncounter->triageAssessment->id)->toBe($triage->id);
});
