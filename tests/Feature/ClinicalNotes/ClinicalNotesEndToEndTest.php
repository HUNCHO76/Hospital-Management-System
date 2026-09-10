<?php

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Services\ClinicalHistoryService;

/**
 * Full clinical chain (spec §41): Reception -> Clinical Encounter -> Triage
 * -> Consultation -> Diagnosis -> Treatment -> Clinical Note -> Sign ->
 * Amend -> verify the original version survives -> view patient clinical
 * history -> verify the full audit trail.
 */
test('a treated encounter flows into clinical note documentation, amendment, and the patient clinical history view over HTTP', function () {
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    Department::factory()->create(['hospital_id' => $ctx['hospital']->id]);

    $encounter = ClinicalEncounter::factory()->create([
        'department_id' => $ctx['department']->id,
        'patient_id' => $ctx['patient']->id,
    ]);

    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 80])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    // A nurse records a nursing note before the doctor is ever involved.
    $this->actingAs($nurse)->post(route('clinical-encounters.clinical-notes.store', $encounter), [
        'note_type' => 'nursing',
        'note_content' => 'Patient triaged, vitals stable.',
    ])->assertRedirect();
    $nursingNote = ClinicalNote::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();

    $diagnosisCode = activeDiagnosisCode(['code' => 'J45.9', 'description' => 'Asthma, unspecified']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.store', $encounter), [
        'goals' => 'Manage asthma symptoms',
    ])->assertRedirect();
    $carePlan = CarePlan::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'clinical_intervention',
        'description' => 'Inhaler education',
    ])->assertRedirect();

    // The doctor documents a progress note, signs it, then amends it.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.clinical-notes.store', $encounter), [
        'note_type' => 'progress',
        'note_content' => 'Patient presents with wheeze, started on inhaler.',
    ])->assertRedirect();

    $progressNote = ClinicalNote::query()->where('clinical_encounter_id', $encounter->id)->where('note_type', 'progress')->firstOrFail();

    $this->actingAs($doctor['user'])
        ->post(route('clinical-encounters.clinical-notes.sign', [$encounter, $progressNote]))
        ->assertRedirect();
    expect($progressNote->fresh()->status)->toBe(ClinicalNote::STATUS_SIGNED);

    $originalContent = $progressNote->fresh()->note_content;

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.clinical-notes.amend', [$encounter, $progressNote]), [
        'note_content' => 'Patient presents with wheeze and mild fever, started on inhaler.',
        'amendment_reason' => 'Omitted fever on initial documentation',
    ])->assertRedirect();

    $fresh = $progressNote->fresh();
    expect($fresh->status)->toBe(ClinicalNote::STATUS_AMENDED);
    expect($fresh->note_content)->not->toBe($originalContent);

    // The original version still exists, unaltered.
    $versions = $fresh->versions()->orderBy('version_number')->get();
    expect($versions)->toHaveCount(2);
    expect($versions[0]->note_content)->toBe($originalContent);
    expect($versions[1]->reason)->toBe('Omitted fever on initial documentation');

    // View the patient's clinical history.
    $response = $this->actingAs($doctor['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('patients/clinical-history')
        ->where('encounters.data.0.id', $encounter->id)
        ->has('encounters.data.0.clinical_notes', 2));

    // Full audit trail.
    foreach ([
        'clinical_note_created',
        'clinical_note_signed',
        'clinical_note_version_created',
        'clinical_note_amended',
        'clinical_history_viewed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked.
    expect($nursingNote->fresh()->clinical_encounter_id)->toBe($encounter->id);
    expect($fresh->patient_id)->toBe($ctx['patient']->id);
    expect($carePlan->fresh()->clinicalEncounter->id)->toBe($encounter->id);

    // The treatment activity surfaces via ClinicalHistoryService directly too.
    $timeline = app(ClinicalHistoryService::class)->timeline($ctx['patient']->fresh());
    expect($timeline->total())->toBe(1);
});
