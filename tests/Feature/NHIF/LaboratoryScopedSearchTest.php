<?php

/**
 * LaboratoryScopedSearchTest — Phase 8
 *
 * Scenarios:
 *  1.  NHIF encounter: searchJson returns only tests whose nhif_code is in the patient's scheme
 *  2.  Tests with nhif_code not in scheme are excluded
 *  3.  Expired scheme service is excluded
 *  4.  Future-start scheme service is excluded
 *  5.  Cash encounter / no encounter: search returns full active list
 *  6.  Response includes nhif_filtered=true when filtered, false otherwise
 */

use App\Models\LaboratoryTest;
use App\Models\NhifSchemeService;
use App\Models\PatientInsurance;

// ---------------------------------------------------------------------------
// Helper — build an NHIF-enabled encounter context
// ---------------------------------------------------------------------------

function nhifLabSetup(): array
{
    // Use the existing diagnosisReadyEncounter pattern but with NHIF insurance
    $ctx = diagnosisReadyEncounter();

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-LAB-001',
        'nhif_scheme_id' => 8001,
    ]);

    // Two tests: one covered, one not
    $covered = LaboratoryTest::factory()->create([
        'nhif_code' => 'LAB-BLOOD',
        'status' => LaboratoryTest::STATUS_ACTIVE,
        'name' => 'Blood Panel',
    ]);
    $excluded = LaboratoryTest::factory()->create([
        'nhif_code' => 'LAB-GENE',
        'status' => LaboratoryTest::STATUS_ACTIVE,
        'name' => 'Genetic Screening',
    ]);

    NhifSchemeService::factory()->create([
        'scheme_id' => 8001,
        'item_code' => 'LAB-BLOOD',
        'item_name' => 'Blood Panel',
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);

    return compact('ctx', 'insurance', 'covered', 'excluded');
}

// ---------------------------------------------------------------------------
// Scenario 1 — NHIF encounter returns only covered tests
// ---------------------------------------------------------------------------

it('searchJson returns only nhif-scheme-covered tests for an NHIF encounter', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=blood&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($setup['covered']->id);
    expect($ids)->not->toContain($setup['excluded']->id);
});

// ---------------------------------------------------------------------------
// Scenario 2 — Tests with nhif_code not in scheme are excluded
// ---------------------------------------------------------------------------

it('test with nhif_code not in patient scheme is excluded from NHIF-scoped search', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=gene&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($setup['excluded']->id);
});

// ---------------------------------------------------------------------------
// Scenario 3 — Expired scheme service excludes the test
// ---------------------------------------------------------------------------

it('test with expired scheme service is excluded from NHIF-scoped search', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    $expired = LaboratoryTest::factory()->create([
        'nhif_code' => 'LAB-EXPIRED',
        'status' => LaboratoryTest::STATUS_ACTIVE,
        'name' => 'Expired Service Test',
    ]);
    NhifSchemeService::factory()->create([
        'scheme_id' => 8001,
        'item_code' => 'LAB-EXPIRED',
        'item_name' => 'Expired Service Test',
        'is_active' => true,
        'start_date' => null,
        'end_date' => now()->subDay(), // expired yesterday
    ]);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=expired&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($expired->id);
});

// ---------------------------------------------------------------------------
// Scenario 4 — Future-start scheme service excludes the test
// ---------------------------------------------------------------------------

it('test with future-start scheme service is excluded from NHIF-scoped search', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    $future = LaboratoryTest::factory()->create([
        'nhif_code' => 'LAB-FUTURE',
        'status' => LaboratoryTest::STATUS_ACTIVE,
        'name' => 'Future Service Test',
    ]);
    NhifSchemeService::factory()->create([
        'scheme_id' => 8001,
        'item_code' => 'LAB-FUTURE',
        'item_name' => 'Future Service Test',
        'is_active' => true,
        'start_date' => now()->addDay(), // starts tomorrow
        'end_date' => null,
    ]);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=future&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($future->id);
});

// ---------------------------------------------------------------------------
// Scenario 5 — No encounter context = full active list
// ---------------------------------------------------------------------------

it('searchJson without clinical_encounter_id returns the full active test list', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    // Without encounter context both tests are returned (nhif_filtered=false)
    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=blood');

    $response->assertOk();
    expect($response->json('nhif_filtered'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Scenario 6 — nhif_filtered flag in response
// ---------------------------------------------------------------------------

it('searchJson sets nhif_filtered=true when filtered by scheme', function (): void {
    $setup = nhifLabSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('laboratory-tests.search-json').'?search=blood&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    expect($response->json('nhif_filtered'))->toBeTrue();
});
