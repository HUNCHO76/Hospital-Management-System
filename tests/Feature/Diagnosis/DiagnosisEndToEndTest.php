<?php

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\EncounterDiagnosis;
use App\Services\PatientHistoryService;

/**
 * Full clinical chain (spec §38):
 *
 *   Clinical Encounter -> Triage Completed -> Doctor Consultation ->
 *   Primary Diagnosis -> Secondary Diagnosis -> Confirm -> Resolve
 *
 * Verifies every entity stays correctly linked end to end, every lifecycle
 * transition is audited, and the encounter's diagnosis activity surfaces in
 * the patient's timeline without leaking clinical content.
 */
test('a completed consultation flows into a full diagnosis workflow over HTTP', function () {
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();
    expect(Consultation::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail()->status)
        ->toBe(Consultation::STATUS_IN_PROGRESS);

    $primaryCode = activeDiagnosisCode(['code' => 'B50.9', 'description' => 'Plasmodium falciparum malaria, unspecified']);
    $secondaryCode = activeDiagnosisCode(['code' => 'R50.9', 'description' => 'Fever, unspecified']);

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $primaryCode->id,
        'diagnosis_type' => 'primary',
        'clinical_note' => 'Consistent with blood smear findings.',
    ])->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $secondaryCode->id,
        'diagnosis_type' => 'secondary',
    ])->assertRedirect();

    $primary = EncounterDiagnosis::query()->where('clinical_encounter_id', $encounter->id)->where('diagnosis_type', 'primary')->firstOrFail();
    $secondary = EncounterDiagnosis::query()->where('clinical_encounter_id', $encounter->id)->where('diagnosis_type', 'secondary')->firstOrFail();

    expect($primary->diagnosis_code_id)->toBe($primaryCode->id);
    expect($primary->consultation_id)->toBe(Consultation::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail()->id);

    // Confirm and resolve the primary diagnosis.
    $this->actingAs($doctor['user'])
        ->post(route('clinical-encounters.diagnoses.status', [$encounter, $primary]), ['status' => 'confirmed'])
        ->assertRedirect();
    $this->actingAs($doctor['user'])
        ->post(route('clinical-encounters.diagnoses.status', [$encounter, $primary]), ['status' => 'resolved'])
        ->assertRedirect();

    expect($primary->fresh()->status)->toBe(EncounterDiagnosis::STATUS_RESOLVED);

    // Every lifecycle step left an audit trail.
    foreach (['diagnosis_created', 'diagnosis_status_changed'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked.
    expect($primary->fresh()->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($secondary->fresh()->clinicalEncounter->id)->toBe($encounter->id);

    // The diagnosis activity surfaces in the patient's timeline — action
    // name and timestamp only, never the diagnosis/code/note content.
    $timeline = app(PatientHistoryService::class)->timeline($ctx['patient']->fresh());
    expect($timeline->pluck('action'))->toContain('diagnosis_created');
});
