<?php

use App\Services\LaboratoryOrderService;
use App\Services\RadiologyOrderService;

/**
 * Phase 6, Step 51, spec §30 — Prescription, Laboratory, and Radiology are
 * independent sibling branches off the same clinical encounter/consultation
 * (each service's own "sibling branch" docblock), never a sequential
 * dependency. A patient must be able to receive all three within the same
 * encounter, in any order, and each branch's own eligibility guard must
 * never reference the others' existence or state.
 */
test('a single encounter can carry a prescription, a laboratory order, and a radiology order at once', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorRequest = requestAs($ctx['doctor']['user']);

    $prescription = activePrescriptionOrderFor($ctx);

    $labOrder = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [activeLaboratoryTest()->id],
    ], $doctorRequest);

    $radiologyOrder = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [activeRadiologyProcedure()->id],
    ], $doctorRequest);

    expect($prescription->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($labOrder->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($radiologyOrder->clinical_encounter_id)->toBe($ctx['encounter']->id);

    $ctx['encounter']->refresh();
    expect($ctx['encounter']->prescriptionOrders()->count())->toBe(1);
    expect($ctx['encounter']->laboratoryOrders()->count())->toBe(1);
    expect($ctx['encounter']->radiologyOrders()->count())->toBe(1);
});

test('placing a radiology order and a laboratory order never requires a prescription to exist first, or vice versa', function () {
    $ctx = diagnosisReadyEncounter();
    $doctorRequest = requestAs($ctx['doctor']['user']);

    // Radiology first, with no prescription and no laboratory order in
    // existence at all — proves RadiologyOrderService's own eligibility
    // guard never checks for a sibling branch.
    $radiologyOrder = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [activeRadiologyProcedure()->id],
    ], $doctorRequest);

    expect($radiologyOrder->id)->not->toBeNull();

    // Laboratory next, still with no prescription in existence.
    $labOrder = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [activeLaboratoryTest()->id],
    ], $doctorRequest);

    expect($labOrder->id)->not->toBeNull();

    // Prescription last — creating it never mutates or depends on either
    // sibling order already placed.
    $prescription = activePrescriptionOrderFor($ctx);

    expect($prescription->id)->not->toBeNull();
    expect($radiologyOrder->fresh()->status)->not->toBeNull();
    expect($labOrder->fresh()->status)->not->toBeNull();
});
