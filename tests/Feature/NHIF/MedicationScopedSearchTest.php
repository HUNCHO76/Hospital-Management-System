<?php

/**
 * MedicationScopedSearchTest — Phase 8
 *
 * Scenarios:
 *  1.  NHIF encounter: searchJson returns only medications whose nhif_code is in the patient's scheme
 *  2.  Medications with nhif_code not in scheme are excluded
 *  3.  Expired scheme service is excluded
 *  4.  Future-start scheme service is excluded
 *  5.  Cash encounter / no encounter: search returns full active list
 *  6.  Response includes nhif_filtered=true when filtered, false otherwise
 */

use App\Models\Medication;
use App\Models\NhifSchemeService;
use App\Models\PatientInsurance;

// ---------------------------------------------------------------------------
// Helper — build an NHIF-enabled encounter context with medications
// ---------------------------------------------------------------------------

function nhifMedSetup(): array
{
    $ctx = diagnosisReadyEncounter();

    PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-MED-001',
        'nhif_scheme_id' => 8002,
    ]);

    // Two medications: one covered by scheme, one not
    $covered = Medication::factory()->create([
        'nhif_code' => 'MED-AMOX',
        'status' => Medication::STATUS_ACTIVE,
        'generic_name' => 'Amoxicillin',
    ]);
    $excluded = Medication::factory()->create([
        'nhif_code' => 'MED-CHEMO',
        'status' => Medication::STATUS_ACTIVE,
        'generic_name' => 'Chemotherapy Drug',
    ]);

    NhifSchemeService::factory()->create([
        'scheme_id' => 8002,
        'item_code' => 'MED-AMOX',
        'item_name' => 'Amoxicillin',
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);

    return compact('ctx', 'covered', 'excluded');
}

// ---------------------------------------------------------------------------
// Scenario 1 — NHIF encounter returns only covered medications
// ---------------------------------------------------------------------------

it('searchJson returns only nhif-scheme-covered medications for an NHIF encounter', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=amox&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($setup['covered']->id);
    expect($ids)->not->toContain($setup['excluded']->id);
});

// ---------------------------------------------------------------------------
// Scenario 2 — Medications with nhif_code not in scheme are excluded
// ---------------------------------------------------------------------------

it('medication with nhif_code not in patient scheme is excluded from NHIF-scoped search', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=chemo&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($setup['excluded']->id);
});

// ---------------------------------------------------------------------------
// Scenario 3 — Expired scheme service excludes the medication
// ---------------------------------------------------------------------------

it('medication with expired scheme service is excluded from NHIF-scoped search', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $expired = Medication::factory()->create([
        'nhif_code' => 'MED-EXPIRED',
        'status' => Medication::STATUS_ACTIVE,
        'generic_name' => 'Expired Drug',
    ]);
    NhifSchemeService::factory()->create([
        'scheme_id' => 8002,
        'item_code' => 'MED-EXPIRED',
        'item_name' => 'Expired Drug',
        'is_active' => true,
        'start_date' => null,
        'end_date' => now()->subDay(), // expired yesterday
    ]);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=expired&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($expired->id);
});

// ---------------------------------------------------------------------------
// Scenario 4 — Future-start scheme service excludes the medication
// ---------------------------------------------------------------------------

it('medication with future-start scheme service is excluded from NHIF-scoped search', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $future = Medication::factory()->create([
        'nhif_code' => 'MED-FUTURE',
        'status' => Medication::STATUS_ACTIVE,
        'generic_name' => 'Future Drug',
    ]);
    NhifSchemeService::factory()->create([
        'scheme_id' => 8002,
        'item_code' => 'MED-FUTURE',
        'item_name' => 'Future Drug',
        'is_active' => true,
        'start_date' => now()->addDay(), // starts tomorrow
        'end_date' => null,
    ]);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=future&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($future->id);
});

// ---------------------------------------------------------------------------
// Scenario 5 — No encounter context = full active list (nhif_filtered=false)
// ---------------------------------------------------------------------------

it('searchJson without clinical_encounter_id returns the full active medication list', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=amox');

    $response->assertOk();
    expect($response->json('nhif_filtered'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Scenario 6 — nhif_filtered flag is true when encounter has NHIF scheme
// ---------------------------------------------------------------------------

it('searchJson sets nhif_filtered=true when filtered by scheme for medications', function (): void {
    $setup = nhifMedSetup();
    $ctx = $setup['ctx'];

    $response = $this->actingAs($ctx['doctor']['user'])
        ->getJson(route('medications.search-json').'?search=amox&clinical_encounter_id='.$ctx['encounter']->id);

    $response->assertOk();
    expect($response->json('nhif_filtered'))->toBeTrue();
});
