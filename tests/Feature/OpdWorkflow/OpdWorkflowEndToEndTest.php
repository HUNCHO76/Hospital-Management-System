<?php

use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\Consultation;
use App\Models\EncounterDiagnosis;
use App\Models\LaboratoryOrder;
use App\Models\ReceptionVisit;

/**
 * The master OPD workflow (Step 27, spec §39): the full chain across every
 * Phase 3 module in one place, ending in a disposition and a check that the
 * patient's clinical history surfaces everything. Every step here already
 * has its own deep unit/HTTP coverage in its own module's test suite (see
 * CarePlanEndToEndTest, ClinicalNotesEndToEndTest, etc.) — this test is the
 * single authoritative "does the whole thing actually connect" reference
 * for Step 27, not a re-derivation of that coverage.
 */
test('a walk-in patient flows through the entire OPD workflow to a disposition and the patient clinical history reflects it', function () {
    $receptionist = userWithRole('receptionist');
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    // 1-3: Reception check-in.
    $this->actingAs($receptionist)->post(route('reception.walk-in.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'visit_type' => 'walk_in',
    ])->assertRedirect();
    $visit = ReceptionVisit::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($receptionist)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    $this->actingAs($receptionist)->post(route('reception.visits.ready', $visit))->assertRedirect();

    // 4: Clinical encounter.
    $this->actingAs($nurse)->post(route('clinical-encounters.store'), [
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'reception_visit_id' => $visit->id,
        'encounter_type' => 'walk_in',
    ])->assertRedirect();
    $encounter = ClinicalEncounter::query()->where('patient_id', $ctx['patient']->id)->firstOrFail();
    $this->actingAs($nurse)->post(route('clinical-encounters.start', $encounter))->assertRedirect();

    // 5-6: Triage and vital signs.
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.store', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.start', $encounter))->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.vital-signs.store', $encounter), ['pulse_bpm' => 78])->assertRedirect();
    $this->actingAs($nurse)->put(route('clinical-encounters.triage.update', $encounter), ['triage_category' => 'urgent'])->assertRedirect();
    $this->actingAs($nurse)->post(route('clinical-encounters.triage.complete', $encounter))->assertRedirect();

    // 7-9: Practitioner assigned via consultation.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.store', $encounter))->assertRedirect();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.consultation.start', $encounter))->assertRedirect();
    $consultation = Consultation::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();

    // 10: Diagnosis.
    $diagnosisCode = activeDiagnosisCode(['code' => 'A09', 'description' => 'Gastroenteritis']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();
    $diagnosis = EncounterDiagnosis::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();

    // 11-13: Treatment/orders — a medication with prescription, and a
    // laboratory order proving the parallel, non-sequential order boundary
    // (spec §4): the encounter is never blocked on the lab item's own,
    // not-yet-built processing.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.store', $encounter), [
        'goals' => 'Rehydrate and confirm diagnosis',
    ])->assertRedirect();
    $carePlan = CarePlan::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();

    $medication = activeMedication(['generic_name' => 'Oral Rehydration Salts']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'medication',
        'description' => 'Rehydration therapy',
        'encounter_diagnosis_id' => $diagnosis->id,
        'prescription' => ['medication_id' => $medication->id],
    ])->assertRedirect();

    $labTest = activeLaboratoryTest(['name' => 'Stool Culture', 'code' => 'STOOL-CS']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.laboratory-orders.store', $encounter), [
        'test_ids' => [$labTest->id],
        'clinical_indication' => 'Confirm gastroenteritis pathogen',
    ])->assertRedirect();
    $labOrder = LaboratoryOrder::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    expect($labOrder->status)->toBe(LaboratoryOrder::STATUS_ORDERED);

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.activate', $encounter))->assertRedirect();

    // Clinical note.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.clinical-notes.store', $encounter), [
        'note_type' => 'progress',
        'note_content' => 'Mild dehydration, started on ORS, stool sample sent.',
    ])->assertRedirect();
    $note = ClinicalNote::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.clinical-notes.sign', [$encounter, $note]))->assertRedirect();
    expect($note->fresh()->status)->toBe(ClinicalNote::STATUS_SIGNED);

    // 15-16: Disposition and completion — the encounter completes even
    // though the lab order is still just "planned", proving a future
    // Laboratory module's own turnaround time never blocks OPD completion.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.complete', $encounter), [
        'disposition' => 'follow_up',
        'disposition_notes' => 'Review once stool culture result is available',
    ])->assertRedirect();

    $fresh = $encounter->fresh();
    expect($fresh->status)->toBe(ClinicalEncounter::STATUS_COMPLETED);
    expect($fresh->disposition)->toBe(ClinicalEncounter::DISPOSITION_FOLLOW_UP);
    expect($fresh->disposition_notes)->toBe('Review once stool culture result is available');
    expect($labOrder->fresh()->status)->toBe(LaboratoryOrder::STATUS_ORDERED);

    // 17-18: Patient clinical history shows everything.
    $response = $this->actingAs($doctor['user'])->get(route('patients.clinical-history', $ctx['patient']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('patients/clinical-history')
        ->where('encounters.data.0.id', $fresh->id)
        ->where('encounters.data.0.disposition', 'follow_up')
        ->where('encounters.data.0.diagnoses.0.id', $diagnosis->id)
        ->has('encounters.data.0.clinical_notes', 1)
        ->has('encounters.data.0.care_plan.items', 1));

    // Every step left an audit trail.
    foreach ([
        'clinical_encounter_created', 'clinical_encounter_started',
        'triage_created', 'vital_sign_recorded', 'triage_completed',
        'consultation_started', 'diagnosis_created',
        'care_plan_created', 'treatment_item_created', 'prescription_created',
        'laboratory_order_created', 'care_plan_activated', 'clinical_note_created', 'clinical_note_signed',
        'clinical_encounter_completed', 'clinical_history_viewed',
    ] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked to the same patient/encounter.
    expect($consultation->fresh()->clinical_encounter_id)->toBe($fresh->id);
    expect($carePlan->fresh()->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($note->fresh()->patient_id)->toBe($ctx['patient']->id);
});
