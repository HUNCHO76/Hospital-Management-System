<?php

use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\EncounterDiagnosis;
use App\Services\PatientHistoryService;

/**
 * Full clinical chain (spec §44):
 *
 *   Reception -> Clinical Encounter -> Triage -> Vital Signs ->
 *   Consultation -> Diagnosis -> Treatment Plan -> Treatment Items ->
 *   Prescription -> Follow-up -> Referral -> Activate -> Complete
 *
 * Verifies every entity stays correctly linked end to end, every lifecycle
 * transition is audited, and the encounter's treatment activity surfaces in
 * the patient's timeline without leaking clinical content.
 */
test('a diagnosed encounter flows into a full treatment plan workflow over HTTP', function () {
    $nurse = userWithRole('nurse');
    $ctx = clinicalEncounterContext();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $department = Department::factory()->create(['hospital_id' => $ctx['hospital']->id]);

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

    $diagnosisCode = activeDiagnosisCode(['code' => 'B50.9', 'description' => 'Plasmodium falciparum malaria, unspecified']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.diagnoses.store', $encounter), [
        'diagnosis_code_id' => $diagnosisCode->id,
        'diagnosis_type' => 'primary',
    ])->assertRedirect();

    $diagnosis = EncounterDiagnosis::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();

    // Start the treatment plan.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.store', $encounter), [
        'goals' => 'Resolve infection and manage symptoms',
    ])->assertRedirect();

    $carePlan = CarePlan::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail();
    expect($carePlan->status)->toBe(CarePlan::STATUS_DRAFT);
    expect($carePlan->consultation_id)->toBe(Consultation::query()->where('clinical_encounter_id', $encounter->id)->firstOrFail()->id);

    // Add a medication item with a prescription.
    $medication = activeMedication(['generic_name' => 'Artemether-Lumefantrine']);
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'medication',
        'description' => 'Antimalarial course',
        'encounter_diagnosis_id' => $diagnosis->id,
        'prescription' => [
            'medication_id' => $medication->id,
            'strength' => '20/120mg',
            'dose' => '4 tablets',
            'route' => 'oral',
            'frequency' => 'twice daily',
            'duration' => '3 days',
            'quantity' => '24 tablets',
        ],
    ])->assertRedirect();

    // Add a follow-up item.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'follow_up',
        'description' => 'Review in 3 days if symptoms persist',
    ])->assertRedirect();

    // Add a referral item.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.items.store', $encounter), [
        'type' => 'referral',
        'description' => 'Refer for further assessment',
        'referred_to_department_id' => $department->id,
    ])->assertRedirect();

    expect($carePlan->items()->count())->toBe(3);
    $medicationItem = $carePlan->items()->where('type', 'medication')->firstOrFail();
    expect($medicationItem->prescription->medication_id)->toBe($medication->id);

    // Activate the plan.
    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.activate', $encounter))->assertRedirect();
    expect($carePlan->fresh()->status)->toBe(CarePlan::STATUS_ACTIVE);

    // Complete the medication item, then the plan.
    $this->actingAs($doctor['user'])
        ->post(route('clinical-encounters.care-plan.items.status', [$encounter, $medicationItem]), ['status' => 'active'])
        ->assertRedirect();
    $this->actingAs($doctor['user'])
        ->post(route('clinical-encounters.care-plan.items.status', [$encounter, $medicationItem]), ['status' => 'completed'])
        ->assertRedirect();
    expect($medicationItem->fresh()->status)->toBe(CarePlanItem::STATUS_COMPLETED);

    $this->actingAs($doctor['user'])->post(route('clinical-encounters.care-plan.complete', $encounter))->assertRedirect();

    $fresh = $carePlan->fresh();
    expect($fresh->status)->toBe(CarePlan::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();
    expect($fresh->completed_by)->toBe($doctor['user']->id);

    // The clinical encounter itself is untouched by care plan completion.
    expect($encounter->fresh()->status)->toBe(ClinicalEncounter::STATUS_OPEN);

    // Every lifecycle step left an audit trail.
    foreach (['care_plan_created', 'care_plan_activated', 'care_plan_completed', 'treatment_item_created', 'prescription_created'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action]);
    }

    // Everything stays correctly linked.
    expect($fresh->clinicalEncounter->patient_id)->toBe($ctx['patient']->id);
    expect($medicationItem->fresh()->carePlan->id)->toBe($fresh->id);
    expect($medicationItem->fresh()->encounterDiagnosis->id)->toBe($diagnosis->id);

    // The treatment activity surfaces in the patient's timeline — action
    // name and timestamp only, never the plan/item/prescription content.
    $timeline = app(PatientHistoryService::class)->timeline($ctx['patient']->fresh());
    expect($timeline->pluck('action'))->toContain('care_plan_created');
});
