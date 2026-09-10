<?php

use App\Models\EncounterDiagnosis;
use App\Services\DiagnosisService;

/**
 * Mandatory concurrency test (spec §33): two clinicians simultaneously
 * promoting different diagnoses to primary on the same encounter must never
 * both end up primary. As with TriageConcurrencyTest/ConsultationConcurrencyTest,
 * the suite runs against a single in-memory SQLite connection which cannot
 * host two genuinely simultaneous transactions — what this proves is the
 * invariant DiagnosisService::setPrimary() guarantees: the demote-then-promote
 * sequence happens *inside* the same transaction as the encounter-row lock,
 * so even back-to-back calls with no externally-visible gap between them
 * never leave two diagnoses both marked primary.
 */
test('two back-to-back setPrimary calls on the same encounter never leave two active primaries', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosisA = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    $diagnosisB = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], $request);

    $diagnosisC = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], $request);

    // Two "simultaneous" promotions targeting different diagnoses.
    $service->setPrimary($diagnosisB, $request);
    $service->setPrimary($diagnosisC->fresh(), $request);

    expect(EncounterDiagnosis::query()->where('clinical_encounter_id', $ctx['encounter']->id)->where('diagnosis_type', 'primary')->count())
        ->toBe(1);
    expect($diagnosisC->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_PRIMARY);
    expect($diagnosisA->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_SECONDARY);
    expect($diagnosisB->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_SECONDARY);
});

test('setPrimary on two different encounters does not conflict', function () {
    $ctxA = diagnosisReadyEncounter();
    $ctxB = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);

    $diagnosisA = $service->create($ctxA['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], requestAs($ctxA['doctor']['user']));

    $diagnosisB = $service->create($ctxB['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], requestAs($ctxB['doctor']['user']));

    $service->setPrimary($diagnosisA, requestAs($ctxA['doctor']['user']));
    $service->setPrimary($diagnosisB, requestAs($ctxB['doctor']['user']));

    expect($diagnosisA->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_PRIMARY);
    expect($diagnosisB->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_PRIMARY);
});
