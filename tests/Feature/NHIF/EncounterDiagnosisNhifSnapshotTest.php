<?php

/**
 * EncounterDiagnosisNhifSnapshotTest — Phase 8
 *
 * Scenarios:
 *  1.  Storing a diagnosis with all three NHIF snapshot fields persists them
 *  2.  Validation error when only nhif_disease_code is provided (missing name+version)
 *  3.  Snapshot fields are preserved after the reference nhif_diseases row is deleted
 *  4.  Diagnosis without NHIF fields stores null (backwards compat)
 *  5.  HTTP endpoint accepts nhif snapshot fields and stores them
 */

use App\Models\EncounterDiagnosis;
use App\Models\NhifDisease;
use App\Services\DiagnosisService;

// ---------------------------------------------------------------------------
// Scenario 1 — all three fields stored
// ---------------------------------------------------------------------------

it('creating a diagnosis with all three NHIF snapshot fields persists them', function (): void {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => $code->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'nhif_disease_code' => 'A00',
        'nhif_disease_name' => 'Cholera',
        'nhif_icd_version_code' => 'ICD10',
    ], requestAs($ctx['doctor']['user']));

    expect($diagnosis->nhif_disease_code)->toBe('A00');
    expect($diagnosis->nhif_disease_name)->toBe('Cholera');
    expect($diagnosis->nhif_icd_version_code)->toBe('ICD10');

    $this->assertDatabaseHas('encounter_diagnoses', [
        'id' => $diagnosis->id,
        'nhif_disease_code' => 'A00',
        'nhif_disease_name' => 'Cholera',
        'nhif_icd_version_code' => 'ICD10',
    ]);
});

// ---------------------------------------------------------------------------
// Scenario 2 — validation error when only one field provided via HTTP
// ---------------------------------------------------------------------------

it('store endpoint returns validation error when only nhif_disease_code is provided', function (): void {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();

    $response = $this->actingAs($ctx['doctor']['user'])
        ->postJson(
            route('clinical-encounters.diagnoses.store', $ctx['encounter']),
            [
                'diagnosis_code_id' => $code->id,
                'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
                'nhif_disease_code' => 'A00',
                // nhif_disease_name and nhif_icd_version_code missing
            ]
        );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['nhif_disease_name']);
});

// ---------------------------------------------------------------------------
// Scenario 3 — snapshot preserved after reference row is deleted
// ---------------------------------------------------------------------------

it('nhif snapshot fields persist after the nhif_diseases reference row is deleted', function (): void {
    $disease = NhifDisease::factory()->create([
        'disease_code' => 'A00',
        'disease_name' => 'Cholera',
        'icd_version_code' => 'ICD10',
    ]);

    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => $code->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'nhif_disease_code' => $disease->disease_code,
        'nhif_disease_name' => $disease->disease_name,
        'nhif_icd_version_code' => $disease->icd_version_code,
    ], requestAs($ctx['doctor']['user']));

    // Delete the reference row
    $disease->delete();

    // Snapshot must still be intact
    $diagnosis->refresh();
    expect($diagnosis->nhif_disease_code)->toBe('A00');
    expect($diagnosis->nhif_disease_name)->toBe('Cholera');
});

// ---------------------------------------------------------------------------
// Scenario 4 — diagnosis without NHIF fields stores null
// ---------------------------------------------------------------------------

it('diagnosis created without NHIF snapshot fields has null snapshot columns', function (): void {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => $code->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user']));

    expect($diagnosis->nhif_disease_code)->toBeNull();
    expect($diagnosis->nhif_disease_name)->toBeNull();
    expect($diagnosis->nhif_icd_version_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Scenario 5 — HTTP endpoint with all three fields stores them
// ---------------------------------------------------------------------------

it('store endpoint persists all three NHIF snapshot fields when provided together', function (): void {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();

    $this->actingAs($ctx['doctor']['user'])
        ->postJson(
            route('clinical-encounters.diagnoses.store', $ctx['encounter']),
            [
                'diagnosis_code_id' => $code->id,
                'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
                'nhif_disease_code' => 'B05',
                'nhif_disease_name' => 'Measles',
                'nhif_icd_version_code' => 'ICD10',
            ]
        )
        ->assertRedirect();

    $this->assertDatabaseHas('encounter_diagnoses', [
        'clinical_encounter_id' => $ctx['encounter']->id,
        'nhif_disease_code' => 'B05',
        'nhif_disease_name' => 'Measles',
    ]);
});
